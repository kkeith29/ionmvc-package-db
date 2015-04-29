<?php

namespace ionmvc\packages\db\results\mysql;

use ionmvc\packages\db\classes\mysql\result;
use ionmvc\packages\db\exceptions\db as db_exception;

class select extends result {

	const format_object = 1;
	const format_array = 2;

	private $results = array();

	public function __construct( $table,$query ) {
		parent::__construct( $table,$query );
		if ( mysqli_num_rows( $this->query ) === 0 ) {
			return false;
		}
		while( $data = mysqli_fetch_array( $this->query ) ) {
			$this->results[] = new result( $this->table,$this->query,$data );
		}
	}

	public function num_rows() {
		return count( $this->results );
	}

	public function results( $format=false ) {
		$args = func_get_args();
		array_unshift( $args,self::format_object );
		return call_user_func_array( array( $this,'handle' ),$args );
	}

	public function rows( $format=false ) {
		$args = func_get_args();
		array_unshift( $args,self::format_array );
		return call_user_func_array( array( $this,'handle' ),$args );
	}

	public function result( $idx=null ) {
		if ( !is_null( $idx ) ) {
			if ( !isset( $this->results[$idx] ) ) {
				return false;
			}
			return $this->results[$idx];
		}
		$row = current( $this->results );
		next( $this->results );
		return ( $row === false ? false : $row );
	}

	public function row() {
		$row = current( $this->results );
		next( $this->results );
		return ( $row === false ? false : $row->to_array() );
	}

	public function first() {
		return reset( $this->results );
	}

	public function handle() {
		$args = func_get_args();
		$format = array_shift( $args );
		if ( count( $args ) === 0 ) {
			return ( $format == self::format_object ? $this->results : array_map( function( $value ) { return $value->to_array(); },$this->results ) );
		}
		switch( $args[0] ) {
			case 'has_results':
				if ( $this->num_rows() === 0 ) {
					return false;
				}
				return true;
			break;
			case 'by_pk':
			case 'by_column':
			case 'list':
				if ( $args[0] == 'by_pk' ) {
					if ( is_null( $this->table ) ) {
						throw new db_exception('Table instance required to use by_pk type');
					}
					$args[1] = $this->table->primary_key();
				}
				$retval = array();
				foreach( $this->results as $result ) {
					$retval[$result->{$args[1]}] = ( $args[0] == 'list' && isset( $args[2] ) ? $result->{$args[2]} : ( $format == self::format_object ? $result : $result->to_array() ) );
				}
				return $retval;
			break;
			case 'pk_only':
			case 'column_only':
				if ( $args[0] == 'pk_only' ) {
					if ( is_null( $this->table ) ) {
						throw new db_exception('Table instance required to use pk_only type');
					}
					$args[1] = $this->table->primary_key();
				}
				$retval = array();
				foreach( $this->results as $result ) {
					$retval[] = $result->{$args[1]};
				}
				return $retval;
			break;
		}
	}

}

?>