<?php

namespace ionmvc\packages\db\classes\mysql;

use ionmvc\classes\autoloader;
use ionmvc\classes\igsr;
use ionmvc\exceptions\app as app_exception;
use ionmvc\packages\db\drivers\mysql as mysql_driver;
use ionmvc\packages\db\exceptions\db as db_exception;

class query {

	const select = 'select';
	const insert = 'insert';
	const update = 'update';
	const delete = 'delete';

	protected $table = null;
	protected $old_clauses = array();
	protected $use_operator = array(
		'where'  => true,
		'having' => true
	);

	public $data = null;

	public static function factory( $type,$table ) {
		$query = autoloader::class_by_type( $type,mysql_driver::class_type_query,array(
			'instance' => true,
			'args' => array(
				$table
			)
		) );
		if ( $query === false ) {
			throw new app_exception( 'Unable to load mysql query type: %s',$type );
		}
		return $query;
	}

	public function __construct( $table=null ) {
		$this->table = $table;
		$this->data = new igsr;
	}

	public function __call( $name,$args ) {
		$operator = 'AND';
		$not = false;
		if ( strpos( $name,'and_' ) === 0 ) {
			$name = str_replace( 'and_','',$name );
			$operator = 'AND';
		}
		elseif ( strpos( $name,'or_' ) === 0 ) {
			$name = str_replace( 'or_','',$name );
			$operator = 'OR';
		}
		if ( strpos( $name,'_not' ) !== false ) {
			$name = str_replace( '_not','',$name );
			$not = true;
		}
		switch( $name ) {
			case 'where':
			case 'where_columns':
				if ( $name == 'where' && !isset( $args[2] ) ) {
					$args[] = null;
				}
				$args[] = $operator;
			break;
			case 'where_in':
			case 'where_like':
			case 'where_between':
				$args[] = $not;
				$args[] = $operator;
			break;
			default:
				throw new db_exception( "Method '%s' does not exists",$name );
			break;
		}
		call_user_func_array( array( $this,"_{$name}" ),$args );
		return $this;
	}

	protected function fill( $args ) {
		$where = array_shift( $args );
		if ( count( $args ) > 0 ) {
			$types = str_split( array_shift( $args ) );
			if ( count( $types ) !== count( $args ) ) {
				throw new db_exception('Number of data types is not equal to the fields provided');
			}
			$position = array();
			foreach( $args as $i => $arg ) {
				if ( is_array( $arg ) ) {
					foreach( $arg as $part => $value ) {
						$pos = 0;
						while( false !== ( $pos = strpos( $where,":{$part}",$pos ) ) && substr( $where,( isset( $where[$pos-1] ) ? ( $pos - 1 ) : $pos ),1 ) !== '\\' ) {
							$where = substr_replace( $where,table::get_format( $types[$i] ),$pos,( strlen( $part ) + 1 ) );
							$position[$pos] = $value;
						}
					}
					continue;
				}
				if ( false !== ( $pos = strpos( $where,'?' ) ) && substr( $where,( isset( $where[$pos-1] ) ? ( $pos - 1 ) : $pos ),1 ) !== '\\' ) {
					$where = substr_replace( $where,table::get_format( $types[$i] ),$pos,1 );
					$position[$pos] = $arg;
				}
			}
			$where = str_replace( array('\?','\:'),array('?',':'),$where );
			ksort( $position );
			$values = array();
			foreach( array_values( $position ) as $value ) {
				$values[] = $this->table->db()->escape( $value );
			}
			$where = vsprintf( $where,$values );
		}
		return $where;
	}

	public function display() {
		echo '<p>' . $this->build() . '</p>';
		return $this;
	}

	public function use_clauses() {
		$args = func_get_args();
		if ( count( $args ) == 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->old_clauses = $this->clauses;
		$this->clauses = $args;
		return $this;
	}

	public function restore_clauses() {
		$this->clauses = $this->old_clauses;
		$this->old_clauses = array();
		return $this;
	}

	public function where_bind() {
		$args = func_get_args();
		$args[0] = $args[0];
		$where = $this->fill( $args );
		$this->data->set('where',( $this->data->is_set('where') ? $this->data->get('where') : '' ) . $where);
		return $this;
	}

	public function where_group( \Closure $function ) {
		$this->use_operator['where'] = false;
		call_user_func( $function,$this );
		$this->use_operator['where'] = true;
		return $this;
	}

	protected function _where( $column,$datum_1,$datum_2,$operator ) {
		$datum_1 = trim( $datum_1 );
		$where = table::encapsulate( $column ) . " {$datum_1}";
		if ( !is_null( $datum_2 ) ) {
			$where .= ' ' . sprintf( table::get_format( $this->table->column_type( $column ) ),$this->table->db()->escape( $datum_2 ) );
		}
		$this->data->set('where',( $this->data->is_set('where') ? $this->data->get('where') . ( $this->use_operator['where'] ? " {$operator} " : '' ) : '' ) . $where);
	}

	protected function _where_columns( $col_1,$op,$col_2,$operator ) {
		$where = table::encapsulate( $this->table->get_column( $col_1 ) ) . " {$op} " . table::encapsulate( $this->table->get_column( $col_2 ) );
		$this->data->set('where',( $this->data->is_set('where') ? $this->data->get('where') . ( $this->use_operator['where'] ? " {$operator} " : '' ) : '' ) . $where);
	}

	protected function _where_in( $column,$data,$not,$operator ) {
		if ( is_array( $data ) ) {
			$data = vsprintf( implode( ',',array_fill( 0,count( $data ),table::get_format( $this->table->column_type( $column ) ) ) ),array_map( array( $this->table->db(),'escape' ),$data ) );
		}
		elseif ( is_object( $data ) ) {
			$data = $data->build();
		}
		$not = ( $not == true ? 'NOT ' : '' );
		$this->_where( $column,"{$not}IN({$data})",null,$operator );
	}

	protected function _where_like( $column,$data,$type,$not=false,$operator=null ) {
		$not = ( $not == true ? 'NOT ' : '' );
		$this->where_bind( ( $this->data->is_set('where') ? ( $this->use_operator['where'] ? " {$operator} " : '' ) : '' ) . table::encapsulate( $column ) . " {$not}LIKE ?",$type,$data );
	}

	protected function _where_between( $column,$datum_1,$datum_2,$not=false,$operator=null ) {
		$not = ( $not == true ? 'NOT ' : '' );
		$type = $this->table->column_type( $column );
		$this->where_bind( ( $this->data->is_set('where') ? ( $this->use_operator['where'] ? " {$operator} " : '' ) : '' ) . table::encapsulate( $column ) . " {$not}BETWEEN ? AND ?",str_repeat( $type,2 ),$datum_1,$datum_2 );
	}

	public function order_by( $column,$direction='asc' ) {
		if ( !in_array( $direction,array('asc','desc','rand') ) ) {
			throw new db_exception('Invalid order by direction');
		}
		$data = ( $direction == 'rand' ? 'RAND()' : table::encapsulate( $column ) . ' ' . strtoupper( $direction ) );
		$this->data->set('order_by[]',$data);
		return $this;
	}

	public function limit( $start,$total=null ) {
		$this->data->set('limit',compact('start','total'));
		return $this;
	}

	public function execute() {
		return $this->table->db()->execute( $this->build(),$this->table );
	}

}

?>