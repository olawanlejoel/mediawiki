<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Handler class for MWExceptions
 * @ingroup Exception
 */
class MWExceptionHandler {

	protected static $reservedMemory;
	protected static $fatalErrorTypes = array(
		E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR,
		/* HHVM's FATAL_ERROR level */ 16777217,
	);

	/**
	 * Install handlers with PHP.
	 */
	public static function installHandler() {
		set_exception_handler( array( 'MWExceptionHandler', 'handleException' ) );
		set_error_handler( array( 'MWExceptionHandler', 'handleError' ) );

		// Reserve 16k of memory so we can report OOM fatals
		self::$reservedMemory = str_repeat( ' ', 16384 );
		register_shutdown_function(
			array( 'MWExceptionHandler', 'handleFatalError' )
		);
	}

	/**
	 * Report an exception to the user
	 * @param Exception $e
	 */
	protected static function report( Exception $e ) {
		global $wgShowExceptionDetails;

		$cmdLine = MWException::isCommandLine();

		if ( $e instanceof MWException ) {
			try {
				// Try and show the exception prettily, with the normal skin infrastructure
				$e->report();
			} catch ( Exception $e2 ) {
				// Exception occurred from within exception handler
				// Show a simpler message for the original exception,
				// don't try to invoke report()
				$message = "MediaWiki internal error.\n\n";

				if ( $wgShowExceptionDetails ) {
					$message .= 'Original exception: ' . self::getLogMessage( $e ) .
						"\nBacktrace:\n" . self::getRedactedTraceAsString( $e ) .
						"\n\nException caught inside exception handler: " . self::getLogMessage( $e2 ) .
						"\nBacktrace:\n" . self::getRedactedTraceAsString( $e2 );
				} else {
					$message .= "Exception caught inside exception handler.\n\n" .
						"Set \$wgShowExceptionDetails = true; at the bottom of LocalSettings.php " .
						"to show detailed debugging information.";
				}

				$message .= "\n";

				if ( $cmdLine ) {
					self::printError( $message );
				} else {
					echo nl2br( htmlspecialchars( $message ) ) . "\n";
				}
			}
		} else {
			$message = "Unexpected non-MediaWiki exception encountered, of type \"" .
				get_class( $e ) . "\"";

			if ( $wgShowExceptionDetails ) {
				$message .= "\n" . MWExceptionHandler::getLogMessage( $e ) . "\nBacktrace:\n" .
					self::getRedactedTraceAsString( $e ) . "\n";
			}

			if ( $cmdLine ) {
				self::printError( $message );
			} else {
				echo nl2br( htmlspecialchars( $message ) ) . "\n";
			}

		}
	}

	/**
	 * Print a message, if possible to STDERR.
	 * Use this in command line mode only (see isCommandLine)
	 *
	 * @param string $message Failure text
	 */
	public static function printError( $message ) {
		# NOTE: STDERR may not be available, especially if php-cgi is used from the
		# command line (bug #15602). Try to produce meaningful output anyway. Using
		# echo may corrupt output to STDOUT though.
		if ( defined( 'STDERR' ) ) {
			fwrite( STDERR, $message );
		} else {
			echo $message;
		}
	}

	/**
	 * If there are any open database transactions, roll them back and log
	 * the stack trace of the exception that should have been caught so the
	 * transaction could be aborted properly.
	 *
	 * @since 1.23
	 * @param Exception $e
	 */
	public static function rollbackMasterChangesAndLog( Exception $e ) {
		$factory = wfGetLBFactory();
		if ( $factory->hasMasterChanges() ) {
			wfDebugLog( 'Bug56269',
				'Exception thrown with an uncommited database transaction: ' .
					MWExceptionHandler::getLogMessage( $e ) . "\n" .
					$e->getTraceAsString()
			);
			$factory->rollbackMasterChanges();
		}
	}

	/**
	 * Exception handler which simulates the appropriate catch() handling:
	 *
	 *   try {
	 *       ...
	 *   } catch ( MWException $e ) {
	 *       $e->report();
	 *   } catch ( Exception $e ) {
	 *       echo $e->__toString();
	 *   }
	 *
	 * @since 1.25
	 * @param Exception $e
	 */
	public static function handleException( $e ) {
		global $wgFullyInitialised;

		self::rollbackMasterChangesAndLog( $e );
		self::logException( $e );
		self::report( $e );

		// Final cleanup
		if ( $wgFullyInitialised ) {
			try {
				// uses $wgRequest, hence the $wgFullyInitialised condition
				wfLogProfilingData();
			} catch ( Exception $e ) {
			}
		}

		// Exit value should be nonzero for the benefit of shell jobs
		exit( 1 );
	}

	/**
	 * @since 1.25
	 * @param int $level Error level raised
	 * @param string $message
	 * @param string $file
	 * @param int $line
	 */
	public static function handleError( $level, $message, $file = null, $line = null ) {
		// Map error constant to error name (reverse-engineer PHP error reporting)
		switch ( $level ) {
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
			case E_RECOVERABLE_ERROR:
			case E_PARSE:
				$levelName = 'Error';
				break;
			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				$levelName = 'Warning';
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
				$levelName = 'Notice';
				break;
			case E_STRICT:
				$levelName = 'Strict Standards';
				break;
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				$levelName = 'Deprecated';
				break;
			case /* HHVM's FATAL_ERROR */ 16777217:
				$levelName = 'Fatal';
				break;
			default:
				$levelName = 'Unknown error';
				break;
		}

		$e = new ErrorException( "PHP $levelName: $message", 0, $level, $file, $line );
		self::logError( $e );

		// This handler is for logging only. Return false will instruct PHP
		// to continue regular handling.
		return false;
	}


	/**
	 * Look for a fatal error as the cause of the request termination and log
	 * as an exception.
	 *
	 * Special handling is included for missing class errors as they may
	 * indicate that the user needs to install 3rd-party libraries via
	 * Composer or other means.
	 *
	 * @since 1.25
	 */
	public static function handleFatalError() {
		self::$reservedMemory = null;
		$lastError = error_get_last();

		if ( $lastError &&
			isset( $lastError['type'] ) &&
			in_array( $lastError['type'], self::$fatalErrorTypes )
		) {
			$msg = "Fatal Error: {$lastError['message']}";
			// HHVM: Class undefined: foo
			// PHP5: Class 'foo' not found
			if ( preg_match( "/Class (undefined: \w+|'\w+' not found)/",
				$lastError['message']
			) ) {
				$msg = <<<TXT
{$msg}

MediaWiki or an installed extension requires this class but it is not embedded directly in MediaWiki's git repository and must be installed separately by the end user.

Please see <a href="https://www.mediawiki.org/wiki/Download_from_Git#Fetch_external_libraries">mediawiki.org</a> for help on installing the required components.
TXT;
			}
			$e = new ErrorException( $msg, 0, $lastError['type'] );
			self::logError( $e );
		}
	}

	/**
	 * Generate a string representation of an exception's stack trace
	 *
	 * Like Exception::getTraceAsString, but replaces argument values with
	 * argument type or class name.
	 *
	 * @param Exception $e
	 * @return string
	 */
	public static function getRedactedTraceAsString( Exception $e ) {
		$text = '';

		foreach ( self::getRedactedTrace( $e ) as $level => $frame ) {
			if ( isset( $frame['file'] ) && isset( $frame['line'] ) ) {
				$text .= "#{$level} {$frame['file']}({$frame['line']}): ";
			} else {
				// 'file' and 'line' are unset for calls via call_user_func (bug 55634)
				// This matches behaviour of Exception::getTraceAsString to instead
				// display "[internal function]".
				$text .= "#{$level} [internal function]: ";
			}

			if ( isset( $frame['class'] ) ) {
				$text .= $frame['class'] . $frame['type'] . $frame['function'];
			} else {
				$text .= $frame['function'];
			}

			if ( isset( $frame['args'] ) ) {
				$text .= '(' . implode( ', ', $frame['args'] ) . ")\n";
			} else {
				$text .= "()\n";
			}
		}

		$level = $level + 1;
		$text .= "#{$level} {main}";

		return $text;
	}

	/**
	 * Return a copy of an exception's backtrace as an array.
	 *
	 * Like Exception::getTrace, but replaces each element in each frame's
	 * argument array with the name of its class (if the element is an object)
	 * or its type (if the element is a PHP primitive).
	 *
	 * @since 1.22
	 * @param Exception $e
	 * @return array
	 */
	public static function getRedactedTrace( Exception $e ) {
		return array_map( function ( $frame ) {
			if ( isset( $frame['args'] ) ) {
				$frame['args'] = array_map( function ( $arg ) {
					return is_object( $arg ) ? get_class( $arg ) : gettype( $arg );
				}, $frame['args'] );
			}
			return $frame;
		}, $e->getTrace() );
	}

	/**
	 * Get the ID for this exception.
	 *
	 * The ID is saved so that one can match the one output to the user (when
	 * $wgShowExceptionDetails is set to false), to the entry in the debug log.
	 *
	 * @since 1.22
	 * @param Exception $e
	 * @return string
	 */
	public static function getLogId( Exception $e ) {
		if ( !isset( $e->_mwLogId ) ) {
			$e->_mwLogId = wfRandomString( 8 );
		}
		return $e->_mwLogId;
	}

	/**
	 * If the exception occurred in the course of responding to a request,
	 * returns the requested URL. Otherwise, returns false.
	 *
	 * @since 1.23
	 * @return string|bool
	 */
	public static function getURL() {
		global $wgRequest;
		if ( !isset( $wgRequest ) || $wgRequest instanceof FauxRequest ) {
			return false;
		}
		return $wgRequest->getRequestURL();
	}

	/**
	 * Get a message formatting the exception message and its origin.
	 *
	 * @since 1.22
	 * @param Exception $e
	 * @return string
	 */
	public static function getLogMessage( Exception $e ) {
		$id = self::getLogId( $e );
		$type = get_class( $e );
		$file = $e->getFile();
		$line = $e->getLine();
		$message = $e->getMessage();
		$url = self::getURL() ?: '[no req]';

		return "[$id] $url   $type from line $line of $file: $message";
	}

	/**
	 * Serialize an Exception object to JSON.
	 *
	 * The JSON object will have keys 'id', 'file', 'line', 'message', and
	 * 'url'. These keys map to string values, with the exception of 'line',
	 * which is a number, and 'url', which may be either a string URL or or
	 * null if the exception did not occur in the context of serving a web
	 * request.
	 *
	 * If $wgLogExceptionBacktrace is true, it will also have a 'backtrace'
	 * key, mapped to the array return value of Exception::getTrace, but with
	 * each element in each frame's "args" array (if set) replaced with the
	 * argument's class name (if the argument is an object) or type name (if
	 * the argument is a PHP primitive).
	 *
	 * @par Sample JSON record ($wgLogExceptionBacktrace = false):
	 * @code
	 *  {
	 *    "id": "c41fb419",
	 *    "type": "MWException",
	 *    "file": "/var/www/mediawiki/includes/cache/MessageCache.php",
	 *    "line": 704,
	 *    "message": "Non-string key given",
	 *    "url": "/wiki/Main_Page"
	 *  }
	 * @endcode
	 *
	 * @par Sample JSON record ($wgLogExceptionBacktrace = true):
	 * @code
	 *  {
	 *    "id": "dc457938",
	 *    "type": "MWException",
	 *    "file": "/vagrant/mediawiki/includes/cache/MessageCache.php",
	 *    "line": 704,
	 *    "message": "Non-string key given",
	 *    "url": "/wiki/Main_Page",
	 *    "backtrace": [{
	 *      "file": "/vagrant/mediawiki/extensions/VisualEditor/VisualEditor.hooks.php",
	 *      "line": 80,
	 *      "function": "get",
	 *      "class": "MessageCache",
	 *      "type": "->",
	 *      "args": ["array"]
	 *    }]
	 *  }
	 * @endcode
	 *
	 * @since 1.23
	 * @param Exception $e
	 * @param bool $pretty Add non-significant whitespace to improve readability (default: false).
	 * @param int $escaping Bitfield consisting of FormatJson::.*_OK class constants.
	 * @return string|bool JSON string if successful; false upon failure
	 */
	public static function jsonSerializeException( Exception $e, $pretty = false, $escaping = 0 ) {
		global $wgLogExceptionBacktrace;

		$exceptionData = array(
			'id' => self::getLogId( $e ),
			'type' => get_class( $e ),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'message' => $e->getMessage(),
		);

		// Because MediaWiki is first and foremost a web application, we set a
		// 'url' key unconditionally, but set it to null if the exception does
		// not occur in the context of a web request, as a way of making that
		// fact visible and explicit.
		$exceptionData['url'] = self::getURL() ?: null;

		if ( $wgLogExceptionBacktrace ) {
			// Argument values may not be serializable, so redact them.
			$exceptionData['backtrace'] = self::getRedactedTrace( $e );
		}

		return FormatJson::encode( $exceptionData, $pretty, $escaping );
	}

	/**
	 * Log an exception to the exception log (if enabled).
	 *
	 * This method must not assume the exception is an MWException,
	 * it is also used to handle PHP exceptions or exceptions from other libraries.
	 *
	 * @since 1.22
	 * @param Exception $e
	 */
	public static function logException( Exception $e ) {
		global $wgLogExceptionBacktrace;

		if ( !( $e instanceof MWException ) || $e->isLoggable() ) {
			$log = self::getLogMessage( $e );
			if ( $wgLogExceptionBacktrace ) {
				wfDebugLog( 'exception', $log . "\n" . $e->getTraceAsString() );
			} else {
				wfDebugLog( 'exception', $log );
			}

			$json = self::jsonSerializeException( $e, false, FormatJson::ALL_OK );
			if ( $json !== false ) {
				wfDebugLog( 'exception-json', $json, 'private' );
			}
		}
	}

	/**
	 * Log an exception that wasn't thrown but made to wrap an error.
	 *
	 * @since 1.25
	 * @param Exception $e
	*/
	protected static function logError( Exception $e ) {
		global $wgLogExceptionBacktrace;

		$log = self::getLogMessage( $e );
		if ( $wgLogExceptionBacktrace ) {
			wfDebugLog( 'error', $log . "\n" . $e->getTraceAsString() );
		} else {
			wfDebugLog( 'error', $log );
		}
	}
}
