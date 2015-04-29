<?php

namespace ionmvc\packages\db\classes;

use ionmvc\classes\app;
use ionmvc\classes\autoloader;
use ionmvc\classes\config;
use ionmvc\packages\db as db_pkg;

class db {

	private $connections = array();
	private $connection = null;

	public static function __callStatic( $method,$args ) {
		$class = app::db()->_current();
		if ( !method_exists( $class,$method ) ) {
			throw new db_exception( "Method '%s' not found",$method );
		}
		return call_user_func_array( array( $class,$method ),$args );
	}

	public function __construct() {
		if ( config::get('db.enabled') !== true ) {
			throw new db_exception('Database use has been disabled');	
		}
		if ( ( $conn_id = config::get('db.connection_id') ) === false ) {
			throw new db_exception('Connection id not found in config');
		}
		$this->_connection( $conn_id,true );
		app::hook()->attach('db',function() {
			app::db()->close();
		});
	}

	private function handle( $connection ) {
		if ( !isset( $this->connections[$connection] ) ) {
			if ( ( $info = config::get("db.connections.{$connection}") ) === false ) {
				throw new db_exception( 'Connection %s not found',$connection );
			}
			$this->connections[$connection] = $info;
		}
		$driver = $this->connections[$connection]['driver'];
		$this->connections[$connection]['instance'] = autoloader::class_by_type( $driver,db_pkg::class_type_driver,array(
			'instance' => true,
			'args'     => array(
				$this->connections[$connection]
			)
		) );
		if ( $this->connections[$connection]['instance'] === false ) {
			throw new app_exception( 'Unable to load db driver: %s',$driver );
		}
	}

	public function _current() {
		return $this->connections[$this->connection]['instance'];
	}

	public static function current() {
		return app::db()->_current();
	}

	private function _connection( $connection,$set_main=false ) {
		$this->handle( $connection );
		if ( $set_main === true ) {
			$this->connection = $connection;
		}
		return $this->connections[$connection]['instance'];
	}

	public static function connection( $connection,$set_main=false ) {
		return app::db()->_connection( $connection,$set_main );
	}

	public function close() {
		foreach( $this->connections as $name => $connection ) {
			if ( !isset( $connection['instance'] ) ) {
				continue;
			}
			$connection['instance']->close();
		}
	}

}

?>