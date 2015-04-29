<?php

namespace ionmvc\packages\db\drivers;

use ionmvc\classes\package;
use ionmvc\packages\db\classes\mysql\result;
use ionmvc\packages\db\classes\mysql\table;
use ionmvc\packages\db\exceptions\db as db_exception;

class mysql {

	const class_type_query = 'ionmvc.db_query_mysql';
	const class_type_result = 'ionmvc.db_result_mysql';

	private $resource;
	private $tables = array();
	private $table_names = null;

	public function __construct( $config ) {
		if ( ( $this->resource = mysqli_connect( $config['host'],$config['user'],$config['pass'] ) ) === false ) {
			throw new db_exception('Database connection failed');
		}
		$this->select_db( $config['db_name'] );
		$this->execute("SET NAMES 'utf8'");
		package::db()->add_type('query-mysql',array(
			'type' => self::class_type_query,
			'type_config' => array(
				'file_prefix' => 'query'
			),
			'path' => 'queries/mysql'
		));
		package::db()->add_type('result-mysql',array(
			'type' => self::class_type_result,
			'type_config' => array(
				'file_prefix' => 'result'
			),
			'path' => 'results/mysql'
		));
	}

	public function resource() {
		return $this->resource;
	}

	public function select_db( $name ) {
		if ( !mysqli_select_db( $this->resource(),$name ) ) {
			throw new db_exception('Unable to select database');
		}
	}

	public function close() {
		if ( !mysqli_close( $this->resource ) ) {
			throw new db_exception('Unable to close database connection');
		}
	}

	public function table( $name ) {
		if ( !array_key_exists( $name,$this->tables ) ) {
			$this->tables[$name] = new table( $name,$this );
		}
		return $this->tables[$name];
	}

	public function escape( $data ) {
		return mysqli_real_escape_string( $this->resource(),$data );
	}

	public function sql_value( $datum,$quote="'",$force=false ) {
		if ( $force == false && $datum === '' ) {
			return 'NULL';
		}
		if ( $force == false && is_numeric( $datum ) ) {
			return $this->escape( $datum );
		}
		return $quote . $this->escape( $datum ) . $quote;
	}

	public function execute( $sql,$table=null ) {
		$type = false;
		if ( ( $pos = strpos( $sql,' ' ) ) !== false ) {
			$type = substr( strtolower( $sql ),0,$pos );
		}
		if ( ( $query = mysqli_query( $this->resource(),$sql ) ) === false ) {
			throw new db_exception( "Unable to execute query (%s) -- Reason: %s",$sql,mysqli_error( $this->resource() ) );
		}
		if ( in_array( $type,array('lock','unlock','create','drop','alter') ) ) {
			return $query;
		}
		return result::factory( $type,$table,$query );
	}

	public function table_list() {
		if ( is_null( $this->table_names ) ) {
			$tables = array();
			$query = $this->execute('SHOW TABLES');
			while( list( $table ) = $query->rows() ) {
				$tables[] = $table;
			}
			$this->table_names = $tables;
		}
		return $this->table_names;
	}

}

?>