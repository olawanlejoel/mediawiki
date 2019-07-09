<?php
/**
 * Wrapper around a BagOStuff that caches data in memory
 *
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
 * @ingroup Cache
 */

/**
 * Wrapper around a BagOStuff that caches data in memory
 *
 * The differences between CachedBagOStuff and MultiWriteBagOStuff are:
 * * CachedBagOStuff supports only one "backend".
 * * There's a flag for writes to only go to the in-memory cache.
 * * The in-memory cache is always updated.
 * * Locks go to the backend cache (with MultiWriteBagOStuff, it would wind
 *   up going to the HashBagOStuff used for the in-memory cache).
 *
 * @ingroup Cache
 * @TODO: Make this class use composition instead of calling super
 */
class CachedBagOStuff extends HashBagOStuff {
	/** @var BagOStuff */
	protected $backend;

	/**
	 * @param BagOStuff $backend Permanent backend to use
	 * @param array $params Parameters for HashBagOStuff
	 */
	function __construct( BagOStuff $backend, $params = [] ) {
		unset( $params['reportDupes'] ); // useless here

		parent::__construct( $params );

		$this->backend = $backend;
		$this->attrMap = $backend->attrMap;
	}

	public function get( $key, $flags = 0 ) {
		$ret = parent::get( $key, $flags );
		if ( $ret === false && !$this->hasKey( $key ) ) {
			$ret = $this->backend->get( $key, $flags );
			$this->set( $key, $ret, 0, self::WRITE_CACHE_ONLY );
		}
		return $ret;
	}

	public function set( $key, $value, $exptime = 0, $flags = 0 ) {
		parent::set( $key, $value, $exptime, $flags );
		if ( !( $flags & self::WRITE_CACHE_ONLY ) ) {
			$this->backend->set( $key, $value, $exptime, $flags & ~self::WRITE_CACHE_ONLY );
		}
		return true;
	}

	public function delete( $key, $flags = 0 ) {
		parent::delete( $key, $flags );
		if ( !( $flags & self::WRITE_CACHE_ONLY ) ) {
			$this->backend->delete( $key );
		}

		return true;
	}

	public function setDebug( $bool ) {
		parent::setDebug( $bool );
		$this->backend->setDebug( $bool );
	}

	public function deleteObjectsExpiringBefore(
		$timestamp,
		callable $progressCallback = null,
		$limit = INF
	) {
		parent::deleteObjectsExpiringBefore( $timestamp, $progressCallback, $limit );

		return $this->backend->deleteObjectsExpiringBefore(
			$timestamp,
			$progressCallback,
			$limit
		);
	}

	public function makeKeyInternal( $keyspace, $args ) {
		return $this->backend->makeKeyInternal( ...func_get_args() );
	}

	public function makeKey( $class, $component = null ) {
		return $this->backend->makeKey( ...func_get_args() );
	}

	public function makeGlobalKey( $class, $component = null ) {
		return $this->backend->makeGlobalKey( ...func_get_args() );
	}

	// These just call the backend (tested elsewhere)
	// @codeCoverageIgnoreStart

	public function add( $key, $value, $exptime = 0, $flags = 0 ) {
		if ( $this->get( $key ) === false ) {
			return $this->set( $key, $value, $exptime, $flags );
		}

		return false; // key already set
	}

	public function incr( $key, $value = 1 ) {
		$n = $this->backend->incr( $key, $value );
		parent::delete( $key );

		return $n;
	}

	public function lock( $key, $timeout = 6, $expiry = 6, $rclass = '' ) {
		return $this->backend->lock( $key, $timeout, $expiry, $rclass );
	}

	public function unlock( $key ) {
		return $this->backend->unlock( $key );
	}

	public function getLastError() {
		return $this->backend->getLastError();
	}

	public function clearLastError() {
		return $this->backend->clearLastError();
	}

	// @codeCoverageIgnoreEnd
}
