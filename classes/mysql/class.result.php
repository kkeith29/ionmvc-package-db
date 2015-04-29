<?php

namespace ionmvc\packages\db\classes\mysql;

use ionmvc\classes\autoloader;
use ionmvc\exceptions\app as app_exception;
use ionmvc\packages\db\drivers\mysql as mysql_driver;
use ionmvc\packages\db\exceptions\db as db_exception;

class result {

	protected $table = null;
	protected $query = null;

	protected $data = array();

	public static function factory( $type,$table,$query ) {
		switch( $type ) {
			case 'set':
				return true;
			break;
			case 'describe':
			case 'show':
				$type = 'select';
			break;
		}
		$result = autoloader::class_by_type( $type,mysql_driver::class_type_result,array(
			'instance' => true,
			'args' => array(
				$table,
				$query
			)
		) );
		if ( $result === false ) {
			throw new app_exception( 'Unable to load mysql result type: %s',$type );
		}
		return $result;
	}

	public function __construct( $table,$query,$data=array() ) {
		$this->table = $table;
		$this->query = $query;
		$this->data  = $data;
	}

	public function __destruct() {
		if ( is_resource( $this->query ) ) {
			mysqli_free_result( $this->query );
		}
	}

	public function __isset( $key ) {
		return isset( $this->data[$key] );
	}

	public function __get( $key ) {
		return ( array_key_exists( $key,$this->data ) ? $this->data[$key] : null );
	}

	public function __set( $key,$value ) {
		$this->data[$key] = $value;
	}

	public function __unset( $key ) {
		unset( $this->data[$key] );
	}

	public function to_array() {
		return $this->data;
	}

	public function get( $key,$retval='' ) {
		if ( isset( $this->data[$key] ) ) {
			return $this->data[$key];
		}
		return $retval;
	}

	public function set( $key,$value,$append=false ) {
		if ( $append === true && isset( $this->data[$key] ) && is_array( $this->data[$key] ) ) {
			$this->data[$key][] = $value;
			return;
		}
		$this->data[] = $value;
		$this->data[$key] = $value;
	}

	public function affected_rows() {
		if ( is_null( $this->table ) ) {
			throw new db_exception('Table not set for this result set');
		}
		return mysqli_affected_rows( $this->table->db()->resource() );
	}

	public function primary_key() {
		$pk = $this->table->primary_key();
		return $this->data[$pk];
	}

}

?>