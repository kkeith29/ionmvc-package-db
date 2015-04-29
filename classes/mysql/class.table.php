<?php

namespace ionmvc\packages\db\classes\mysql;

use ionmvc\classes\app;
use ionmvc\classes\cache;
use ionmvc\classes\config;
use ionmvc\packages\db\classes\db;
use ionmvc\packages\db\exceptions\db as db_exception;

class table {

	const cache_expr = 5; //days

	private static $data_types = array(
		'char' => 's',
		'varchar' => 's',
		'tinytext' => 's',
		'text' => 's',
		'mediumtext' => 's',
		'longtext' => 's',
		'binary' => 's',
		'varbinary' => 's',
		'tinyblob' => 's',
		'blob' => 's',
		'longblob' => 's',
		'enum' => 's',
		'set' => 's',
		'tinyint' => 'i',
		'smallint' => 'i',
		'mediumint' => 'i',
		'int' => 'i',
		'integer' => 'i',
		'bigint' => 'i',
		'float' => 's',
		'double' => 's',
		'real' => 'i',
		'decimal' => 's',
		'bit' => 'i',
		'bool' => 'i',
		'serial' => 'i',
		'datetime' => 's',
		'date' => 's'
	);

	private $db = null;
	private $pk = null;
	private $table = null;
	private $types = array();
	private $unique = array();
	private $fields = array();
	private $relationships = array();
	private $last_id = null;
	private $last_data = array();

	public function __construct( $table,$db ) {
		$this->table = $table;
		$this->db = $db;
		if ( app::mode_production() ) {
			$cache = new cache('storage-cache:db/tables');
			$cache->id( $table );
			if ( ( $data = $cache->fetch( self::cache_expr,cache::day ) ) !== false ) {
				$this->pk = $data['pk'];
				$this->unique = $data['unique'];
				$this->types = $data['types'];
				$this->fields = array_keys( $this->types );
				return;
			}
		}
		$query = $this->db->execute("DESCRIBE `{$table}`");
		foreach( $query->results() as $result ) {
			list( $field,$type,,$key ) = $result->to_array();
			$this->fields[] = $field;
			if ( $key == 'PRI' ) {
				$this->pk = $field;
			}
			elseif ( $key == 'UNI' ) {
				$this->unique[] = $field;
			}
			if ( false !== ( $pos = strpos( $type,'(' ) ) ) {
				$type = substr( $type,0,$pos );
			}
			if ( !isset( self::$data_types[$type] ) ) {
				throw new db_exception( "Data type '%s' not found",$type );
			}
			$this->types[$field] = self::$data_types[$type];
		}
		if ( isset( $cache ) ) {
			$cache->set_data(array(
				'pk'     => $this->pk,
				'unique' => $this->unique,
				'types'  => $this->types
			));
		}
	}

	public function db() {
		return $this->db;
	}

	public function name() {
		return $this->table;
	}

	public function primary_key() {
		if ( is_null( $this->pk ) ) {
			throw new db_exception( "No primary key has been found for table '%s'",$this->table );
		}
		return $this->pk;
	}

	public function unique_keys() {
		return $this->unique;
	}

	public function column_exists( $column ) {
		return in_array( $column,$this->fields );
	}

	public function columns() {
		return $this->fields;
	}

	public function column_type( $column ) {
		$column = self::parse_column( $column );
		if ( !isset( $this->types[$column['name']] ) ) {
			throw new db_exception( "Invalid column '%s' for %s, type could not be determined",$column['name'],$this->table );
		}
		return $this->types[$column['name']];
	}

	public function get_column( $column ) {
		if ( is_null( $column ) || !in_array( $column,$this->unique ) ) { //remove this
			$column = $this->primary_key();
		}
		return $column;
	}

	public function query( $type ) {
		return query::factory( $type,$this );
	}

	public function last_id() {
		return $this->last_id;
	}

	public function last_data() {
		return $this->last_data;
	}

	public function create( $data ) {
		$this->last_id = $this->query('insert')->fields($data)->execute()->insert_id();
		$this->last_data = $data;
		return $this->last_id;
	}

	public function read( $id,$col=null ) {
		$column = $this->get_column( $col );
		$query = $this->query('select')->where($column,'=',$id)->limit(1)->execute();
		if ( $query->num_rows() == 1 ) {
			return $query->result();
		}
		return false;
	}

	public function update( $data,$id,$col=null ) {
		$column = $this->get_column( $col );
		$query = $this->query('update')->fields($data)->where($column,'=',$id)->limit(1)->execute();
		if ( $query->affected_rows() == 1 ) {
			return true;
		}
		return false;
	}

	public function delete( $id,$col=null ) {
		$column = $this->get_column( $col );
		$query = $this->query('delete')->where($column,'=',$id)->limit(1)->execute();
		if ( $query->affected_rows() == 1 ) {
			return true;
		}
		return false;
	}

	public function get_all() {
		return $this->query('select')->execute()->results();
	}

	public function count() {
		return $this->query('select')->count('*','count')->execute()->result()->count;
	}

	public function dump() {
		$query = $this->query('select')->execute();
		$html = '<div><table cellpadding="3" cellspacing="0" style="background-color:#fff" border="1"><tr style="background-color:#ccc"><td colspan="' . count( $this->fields ) . "\" style=\"width:75%\">Table: {$this->table}" . str_repeat('&nbsp;',10) . "Number of Rows: " . $query->num_rows() . '</tr><tr>';
		foreach( $this->fields as $field ) {
			$html .= "<th>{$field}</th>";
		}
		$html .= '</tr>';
		while( $row = $query->row() ) {
			$html .= '<tr>';
			foreach( $row as $data ) {
				$html .= "<td>{$data}</td>";
			}
			$html .= '</tr>';
		}
		$html .= '</table></div>';
		echo $html;
	}

	public static function encapsulate( $column ) {
		if ( $column == '*' ) {
			return $column;
		}
		$column = self::parse_column( $column );
		if ( isset( $column['custom'] ) ) {
			return $column['custom'];
		}
		return ( $column['table'] === false ? '' : "`{$column['table']}`." ) . "`{$column['name']}`";
	}

	public static function parse_column( $column ) {
		$retval = array(
			'alias' => false
		);
		if ( false !== ( $pos = stripos( $column,' AS ' ) ) ) {
			list( $column,$retval['alias'] ) = explode( substr( $column,$pos,4 ),$column,2 );
			$retval['alias'] = str_replace( '`','',$retval['alias'] );
		}
		if ( strpos( $column,'(' ) !== false && strpos( $column,')' ) !== false ) {
			$retval['custom'] = $column;
			return $retval;
		}
		$retval['name'] = str_replace( '`','',$column );
		$retval['table'] = false;
		if ( strpos( $column,'.' ) !== false ) {
			list( $retval['table'],$retval['name'] ) = explode( '.',$retval['name'] );
		}
		return $retval;
	}

	public static function get_format( $type ) {
		switch( $type ) {
			case 'i': //numeric
				$str = '%d';
			break;
			case 'b': //numeric
			case 'd':
			case 'u':
			case 'o':
				$str = "%{$type}";
			break;
			case 'c': //strings
			case 'e':
			case 'f':
			case 's':
			case 'x':
			case 'X':
				$str = "'%{$type}'";
			break;
			case 'n': //custom type
				$str = '%s';
			break;
			case db::like_any: //mysql like types
				$str = "'%%%s%%'";
			break;
			case db::like_start:
				$str = "'%%%s'";
			break;
			case db::like_end:
				$str = "'%s%%'";
			break;
			default:
				throw new db_exception( "Invalid data type '%s'",$type );
			break;
		}
		return $str;
	}

}

?>