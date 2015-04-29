<?php

namespace ionmvc\packages\db\queries\mysql;

use ionmvc\classes\array_func;
use ionmvc\classes\cache;
use ionmvc\classes\config;
use ionmvc\classes\igsr;
use ionmvc\packages\db\exceptions\db as db_exception;
use ionmvc\packages\db\classes\mysql\query;
use ionmvc\packages\db\classes\mysql\table;

class select extends query {

	private $curr = 1;

	protected $clauses = array('join','where','group_by','having','order_by','limit');
	protected $field_aliases = array();
	protected $cache_id = null;
	protected $cache_expiration = null;

	protected $joins = array();
	protected $tables = array();
	protected $table_aliases = array(
		1 => 't1'
	);
	protected $table_fields = array();

	public function __construct( $table ) {
		parent::__construct( $table );
		$this->tables[1] = $this->table->name();
	}

	public function __call( $name,$args ) {
		switch( $name ) {
			//ability to override the automatic parsing and do what you want
			case 'count':
			case 'max':
			case 'min':
			case 'avg':
			case 'sum':
			case 'unix_timestamp':
				array_unshift( $args,$name );
				if ( isset( $args[2] ) && !isset( $args[3] ) ) {
					$args[3] = 'i';
				}
				return call_user_func_array( array( $this,'func' ),$args );
			break;
			case 'join':
			case 'inner_join':
			case 'left_join':
			case 'right_join':
				$type = 'inner';
				if ( strpos( $name,'_' ) !== false ) {
					list( $type ) = explode( '_',$name,2 );
				}
				array_unshift( $args,$type );
				call_user_func_array( array( $this,'_join' ),$args );
				return $this;
			break;
		}
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
			case 'on':
			case 'on_columns':
			case 'where':
			case 'where_columns':
			case 'having':
			case 'having_columns':
				if ( ( $name == 'where' || $name == 'on' ) && !isset( $args[2] ) ) {
					$args[] = null;
				}
				$args[] = $operator;
			break;
			case 'where_in':
			case 'where_like':
			case 'where_between':
			case 'having_in':
			case 'having_like':
			case 'having_between':
				$args[] = $not;
				$args[] = $operator;
			break;
			case 'where_group':
			case 'having_group':
				$args[] = $operator;
			break;
			default:
				throw new db_exception( "Method '%s' does not exists",$name );
			break;
		}
		call_user_func_array( array( $this,"_{$name}" ),$args );
		return $this;
	}

	public function clear_fields( $all=true ) {
		if ( $all === true ) {
			$this->table_fields = array();
			$this->field_aliases = array();
		}
		foreach( $this->table_fields as $idx => $fields ) {
			foreach( $fields as $i => $data ) {
				if ( isset( $data['custom'] ) ) {
					continue;
				}
				unset( $this->table_fields[$idx][$i] );
			}
		}
		return $this;
	}

	public function clear_one_to_many() {
		$this->one_to_many = array();
		return $this;
	}

	public function is_table_alias( $table ) {
		return in_array( $table,$this->table_aliases );
	}

	public function is_field_alias( $field ) {
		return isset( $this->field_aliases[$field] );
	}

	public function get_table_alias( $idx,$config=array() ) {
		if ( isset( $config['type'] ) ) {
			switch( $config['type'] ) {
				case 'alias':
					switch( $idx ) {
						case 'main':
						case 'first':
							$idx = 1;
						break;
						case 'prev':
							$idx = ( ( $this->curr - 1 ) < 1 ? 1 : ( $this->curr - 1 ) );
							//update to error out if the value is lower than 1
						break;
						case 'curr':
							$idx = $this->curr;
						break;
						case 'last':
							$keys = array_keys( $this->table_aliases );
							$idx = end( $keys );
							unset( $keys );
						break;
						default:
							throw new db_exception('Invalid value for alias');
						break;
					}
				break;
				case 'table':
					if ( ( $idx = array_search( $idx,$this->tables ) ) === false ) {
						throw new db_exception('Unable to find table');
					}
				break;
			}
		}
		if ( isset( $config['return_idx'] ) && $config['return_idx'] == true ) {
			return $idx;
		}
		if ( isset( $this->table_aliases[$idx] ) ) {
			return $this->table_aliases[$idx];
		}
		return false;
	}

	public function table_alias( $name ) {
		$this->table_aliases[$this->curr] = $name;
		return $this;
	}

	public function field_alias( $name,$type,$bypass=false ) {
		if ( isset( $this->field_aliases[$name] ) ) {
			throw new db_exception( "Alias '%s' already in use",$name );
		}
		if ( is_null( $type ) && $bypass === false ) {
			throw new db_exception('Alias type is required when defining an alias');
		}
		$this->field_aliases[$name] = $type;
		return $this;
	}

	protected function _parse_str( $data ) {
		return $this->get_table_alias( $data[2],array(
			'type' => $data[1]
		) );
	}

	protected function parse_str( $data ) {
		return preg_replace_callback( '#{(alias|table):([^}]+)}#',array( $this,'_parse_str' ),str_replace( '{table}',$this->table->name(),$data ) );
	}

	protected function column_type( $column ) {
		$column = table::parse_column( $column );
		if ( $this->is_field_alias( $column['name'] ) ) {
			return $this->field_aliases[$column['name']]['type'];
		}
		if ( $column['table'] !== false && $this->is_table_alias( $column['table'] ) ) {
			if ( ( $idx = array_search( $column['table'],$this->table_aliases ) ) === false ) {
				throw new db_exception('Unable to find table name for alias');
			}
			$column['table'] = ( $idx === 1 ? false : $this->joins[$idx]['table'] );
		}
		if ( $column['table'] === false || $column['table'] == $this->table->name() ) {
			return $this->table->column_type( $column['name'] );
		}
		return $this->table->db()->table( $column['table'] )->column_type( $column['name'] );
	}

	protected function get_column( $column,$raw=false,$alias=false ) {
		$column = table::parse_column( $this->parse_str( $column ) );
		if ( $column['table'] === false && !isset( $this->field_aliases[$column['name']] ) ) {
			$column['table'] = $this->table_aliases[( $alias == false ? $this->curr : $alias )];
		}
		if ( $raw === true ) {
			return $column;
		}
		return ( $column['table'] !== false ? $column['table'] . '.' : '' ) . $column['name'];
	}

	public function fields() {
		$data = func_get_args();
		$data = array_func::flatten( $data );
		foreach( $data as $i => $value ) {
			$this->_field( $value );
		}
		return $this;
	}

	public function field( $name,$alias=null,$alias_type=null ) {
		$this->_field( $name,$alias,$alias_type );
		return $this;
	}

	public function func( $func,$column,$alias=null,$alias_type=null ) {
		$this->_field( strtoupper( $func ) . '(' . table::encapsulate( $column ) . ')',$alias,$alias_type );
		return $this;
	}

	public function concat( $fields,$alias=null,$alias_type='s' ) {
		foreach( $fields as $i => $field ) {
			$fields[$i] = table::encapsulate( $this->get_column( $field ) );
		}
		$this->_field( 'CONCAT(' . implode( ',',$fields ) . ')',$alias,$alias_type );
		return $this;
	}

	public function concat_ws( $sep,$fields,$alias=null,$alias_type='s' ) {
		foreach( $fields as $i => $field ) {
			$fields[$i] = table::encapsulate( $this->get_column( $field ) );
		}
		$this->_field( "CONCAT_WS('{$sep}'," . implode( ',',$fields ) . ')',$alias,$alias_type );
		return $this;
	}

	public function distance( $lat_col,$lng_col,$lat,$lng,$alias=null,$alias_type='i' ) {
		$lat_col = table::encapsulate( $this->get_column( $lat_col ) );
		$lng_col = table::encapsulate( $this->get_column( $lng_col ) );
		$this->_field( "( 3959 * acos( cos( radians('{$lat}') ) * cos( radians( {$lat_col} ) ) * cos( radians( {$lng_col} ) - radians('{$lng}') ) + sin( radians('{$lat}') ) * sin( radians( {$lat_col} ) ) ) )",$alias,$alias_type );
		return $this;
	}

	public function subquery( $sql,$alias=null,$alias_type=null ) {
		$this->_field( '(' . ( is_object( $sql ) ? $sql->build() : $sql ) . ')',$alias,$alias_type );
		return $this;
	}

	private function parse_term( $search ) {
		if ( strlen( trim( $search ) ) == 0 ) {
			return false;
		}
		$exact = array();
		$start = null;
		$i = 0;
		while( $i < strlen( $search ) ) {
			$char = $search[$i];
			switch(true) {
				case ( $char == '"' && $search[( ( $i - 1 ) > 0 ? ( $i - 1 ) : 0 )] !== '\\' ):
					if ( is_null( $start ) ) {
						$start = $i;
					}
					else {
						$str = substr( $search,( $start + 1 ),( $i - $start - 1 ) );
						$search = substr_replace( $search,'',$start,( $i - $start + 1 ) );
						$exact[] = $str;
						$start = null;
					}
				break;
			}
			$i++;
		}
		$phrases = array_filter( explode( ' ',trim( $search ) ) );
		$include = array();
		$exclude = array();
		foreach( $phrases as $i => $phrase ) {
			if ( strlen( $phrase ) == 1 ) {
				continue;
			}
			if ( substr( $phrase,0,1 ) == '-' ) {
				$exclude[] = substr_replace( $phrase,'',0,1 );
				unset( $phrases[$i] );
				continue;
			}
			elseif ( substr( $phrase,0,1 ) == '+' ) {
				$include[] = substr_replace( $phrase,'',0,1 );
				unset( $phrases[$i] );
				continue;
			}
			$phrases[$i] = str_replace( array('\+','\-','\"'),'',$phrase );
		}
		return array( $exact,$phrases,$include,$exclude );
	}

	public function fulltext_search( $term,$fields ) {
		foreach( $fields as &$field ) {
			$field = table::encapsulate( $this->get_column( $field ) );
		}
		$str = 'MATCH(' . implode( ',',$fields ) . ') AGAINST(\'';
		$first = true;
		foreach( $this->parse_term( $term ) as $type ) {
			if ( count( $type ) === 0 ) {
				continue;
			}
			foreach( $type as &$_type ) {
				$_type = $this->table->db()->sql_value( $_type,'"',true );
			}
			$str .= ( $first === false ? ' ' : '' ) . implode( ' ',$type );
			$first = false;
		}
		$str .= '\' IN BOOLEAN MODE)';
		$this->_field( "({$str})",'relevance','i' );
		$this->where_raw( $this->get_operator( 'where','AND' ) . $str );
		$this->order_by('relevance','desc');
		return $this;
	}

	private function _field( $data,$alias=null,$alias_type=null ) {
		$data = table::parse_column( $this->parse_str( $data ) );
		if ( isset( $data['custom'] ) || $data['table'] === false ) {
			$idx = $this->curr;
		}
		elseif ( $data['table'] !== false && $this->is_table_alias( $data['table'] ) ) {
			if ( ( $idx = array_search( $data['table'],$this->table_aliases ) ) === false ) {
				throw new db_exception('Unable to find table name for alias');
			}
		}
		$bypass = false;
		if ( $data['alias'] !== false ) {
			$alias = $data['alias'];
			$bypass = true;
		}
		if ( !is_null( $alias ) ) {
			$this->field_alias( $alias,$alias_type,( is_null( $alias_type ) ? $bypass : false ) );
		}
		$key = ( isset( $data['custom'] ) ? 'custom' : 'name' );
		$this->table_fields[$idx][] = array(
			$key => $data[$key],
			'alias' => $alias
		);
	}

	private function _join( $type,$table,$alias=null ) {
		$this->joins[++$this->curr] = compact('type','table','alias');
		$this->tables[$this->curr] = $table;
		$this->table_aliases[$this->curr] = ( !is_null( $alias ) ? $alias : "t{$this->curr}" );
		$this->use_operator["join.{$this->curr}"] = true;
	}

	public function on_raw() {
		$args = func_get_args();
		$args[0] = $this->parse_str( $args[0] );
		$on = $this->fill( $args );
		$this->data->set( "join.{$this->curr}",$on,igsr::append );
		return $this;
	}

	protected function _on_group( \Closure $function,$operator ) {
		$key = "join.{$this->curr}";
		$this->on_raw(( $this->data->is_set( $key ) ? " {$operator} " : '' ) . '(');
		$this->use_operator[$key] = false;
		call_user_func( $function,$this );
		$this->use_operator[$key] = true;
		$this->on_raw(')');
	}

	protected function _on( $column,$datum_1,$datum_2,$operator='AND' ) {
		$column = $this->get_column( $column );
		$datum_1 = trim( $datum_1 );
		$on = table::encapsulate( $column ) . " {$datum_1}";
		if ( !is_null( $datum_2 ) ) {
			$on .= ' ' . sprintf( table::get_format( $this->column_type( $column ) ),$this->table->db()->escape( $datum_2 ) );
		}
		$key = "join.{$this->curr}";
		$this->data->set( $key,$this->get_operator( $key,$operator ) . $on,igsr::append );
	}

	protected function _on_columns( $col_1,$op,$col_2,$operator='AND' ) {
		$on = table::encapsulate( $this->get_column( $col_1,false,$this->get_table_alias('prev',array('type'=>'alias','return_idx'=>true)) ) ) . " {$op} " . table::encapsulate( $this->get_column( $col_2 ) );
		$key = "join.{$this->curr}";
		$this->data->set( $key,$this->get_operator( $key,$operator ) . $on,igsr::append );
	}

	protected function get_operator( $type,$operator ) {
		$operator = ( $this->data->is_set( $type ) && $this->use_operator[$type] ? " {$operator} " : '' );
		if ( !$this->use_operator[$type] ) {
			$this->use_operator[$type] = true;
		}
		return $operator;
	}

	public function where_raw() {
		$args = func_get_args();
		$args[0] = $this->parse_str( $args[0] );
		$where = $this->fill( $args );
		$this->data->set( 'where',$where,igsr::append );
		return $this;
	}

	protected function _where_group( \Closure $function,$operator ) {
		$this->where_raw(( $this->data->is_set('where') ? " {$operator} " : '' ) . '(');
		$this->use_operator['where'] = false;
		call_user_func( $function,$this );
		$this->use_operator['where'] = true;
		$this->where_raw(')');
	}

	protected function _where( $column,$datum_1,$datum_2,$operator ) {
		$column = $this->get_column( $column );
		$datum_1 = trim( $datum_1 );
		$where = table::encapsulate( $column ) . " {$datum_1}";
		if ( !is_null( $datum_2 ) ) {
			$where .= ' ' . sprintf( table::get_format( $this->column_type( $column ) ),$this->table->db()->escape( $datum_2 ) );
		}
		$this->data->set( 'where',$this->get_operator( 'where',$operator ) . $where,igsr::append );
	}

	protected function _where_columns( $col_1,$op,$col_2,$operator ) {
		$where = table::encapsulate( $this->get_column( $col_1 ) ) . " {$op} " . table::encapsulate( $this->get_column( $col_2 ) );
		$this->data->set( 'where',$this->get_operator( 'where',$operator ) . $where,igsr::append );
	}

	protected function _where_in( $column,$data,$not,$operator ) {
		$column = $this->get_column( $column );
		if ( is_array( $data ) ) {
			$data = vsprintf( implode( ',',array_fill( 0,count( $data ),table::get_format( $this->column_type( $column ) ) ) ),array_map( array( $this->table->db(),'escape' ),$data ) );
		}
		elseif ( is_object( $data ) ) {
			$data = $data->build();
		}
		$not = ( $not == true ? 'NOT ' : '' );
		$this->_where( $column,"{$not}IN({$data})",null,$operator );
	}

	protected function _where_like( $column,$data,$type,$not=false,$operator=null ) {
		$not = ( $not == true ? 'NOT ' : '' );
		$this->where_raw( $this->get_operator( 'where',$operator ) . table::encapsulate( $this->get_column( $column ) ) . " {$not}LIKE ?",$type,$data );
	}

	protected function _where_between( $column,$datum_1,$datum_2,$not=false,$operator=null ) {
		$not = ( $not == true ? 'NOT ' : '' );
		$column = $this->get_column( $column );
		$type = $this->column_type( $column );
		$this->where_raw( $this->get_operator( 'where',$operator ) . table::encapsulate( $column ) . " {$not}BETWEEN ? AND ?",str_repeat( $type,2 ),$datum_1,$datum_2 );
	}

	public function group_by() {
		$args = func_get_args();
		$args = array_func::flatten( $args );
		foreach( $args as $column ) {
			$this->data->set('group_by[]',table::encapsulate( $this->get_column( $column ) ));
		}
		return $this;
	}

	public function having_raw() {
		$args = func_get_args();
		$args[0] = $this->parse_str( $args[0] );
		$having = $this->fill( $args );
		$this->data->set( 'having',$having,igsr::append );
		return $this;
	}

	protected function _having_group( \Closure $function ) {
		$this->having_raw(( $this->data->is_set('having') ? " {$operator} " : '' ) . '(');
		$this->use_operator['having'] = false;
		call_user_func( $function,$this );
		$this->use_operator['having'] = true;
		$this->having_raw(')');
	}

	protected function _having( $column,$datum_1,$datum_2,$operator ) {
		$column = $this->parse_str( $column );
		$datum_1 = trim( $datum_1 );
		$having = table::encapsulate( $column ) . " {$datum_1}";
		if ( !is_null( $datum_2 ) ) {
			$having .= ' ' . sprintf( table::get_format( $this->column_type( $column ) ),$this->table->db()->escape( $datum_2 ) );
		}
		$this->data->set( 'having',$this->get_operator( 'having',$operator ) . $having,igsr::append );
	}

	protected function _having_columns( $col_1,$op,$col_2,$operator ) {
		$having = table::encapsulate( $this->get_column( $col_1 ) ) . " {$op} " . table::encapsulate( $this->get_column( $col_2 ) );
		$this->data->set( 'having',$this->get_operator( 'having',$operator ) . $having,igsr::append );
	}

	protected function _having_in( $column,$data,$not,$operator ) {
		$column = $this->get_column( $column );
		if ( is_array( $data ) ) {
			$data = vsprintf( implode( ',',array_fill( 0,count( $data ),table::get_format( $this->column_type( $column ) ) ) ),array_map( array( $this->table->db(),'escape' ),$data ) );
		}
		elseif ( is_object( $data ) ) {
			$data = $data->build();
		}
		$not = ( $not == true ? 'NOT ' : '' );
		$this->_having( $column,"{$not}IN({$data})",null,$operator );
	}

	protected function _having_like( $column,$data,$type,$not=false,$operator=null ) {
		$not = ( $not == true ? 'NOT ' : '' );
		$this->having_raw( $this->get_operator( 'having',$operator ) . table::encapsulate( $this->get_column( $column ) ) . " {$not}LIKE ?",$type,$data );
	}

	protected function _having_between( $column,$datum_1,$datum_2,$not=false,$operator=null ) {
		$not = ( $not == true ? 'NOT ' : '' );
		$column = $this->get_column( $column );
		$type = $this->column_type( $column );
		$this->having_raw( $this->get_operator( 'having',$operator ) . table::encapsulate( $column ) . " {$not}BETWEEN ? AND ?",str_repeat( $type,2 ),$datum_1,$datum_2 );
	}

	public function cache( $time,$type=null ) {
		$this->cache_expiration = compact('time','type');
		return $this;
	}

	public function build() {
		$fields = array();
		foreach( $this->table_fields as $idx => $_fields ) {
			foreach( $_fields as $field ) {
				$fields[] = ( isset( $field['custom'] ) ? $field['custom'] : "`{$this->table_aliases[$idx]}`." . ( $field['name'] !== '*' ? "`{$field['name']}`" : $field['name'] ) ) . ( !is_null( $field['alias'] ) ? " AS `{$field['alias']}`" : '' );
			}
		}
		if ( count( $fields ) === 0 ) {
			$fields[] = '*';
		}
		$query = 'SELECT ' . implode( ',',$fields ) . ' FROM `' . $this->table->name() . "` AS `{$this->table_aliases[1]}`";
		foreach( $this->clauses as $part ) {
			if ( !$this->data->is_set( $part ) ) {
				continue;
			}
			$data = $this->data->get( $part );
			switch( $part ) {
				case 'join':
					foreach( $this->joins as $idx => $join ) {
						if ( !isset( $data[$idx] ) ) {
							throw new db_exception('No ON clause found for join statement');
						}
						$query .= ' ' . strtoupper( $join['type'] ) . " JOIN `{$join['table']}` AS `{$this->table_aliases[$idx]}`"/* . ( !is_null( $join['alias'] ) ? " AS `{$join['alias']}`" : '' )*/ . " ON {$data[$idx]}";
					}
				break;
				case 'where':
					$query .= " WHERE {$data}";
				break;
				case 'order_by':
					$query .= ' ORDER BY ' . implode( ',',$data );
				break;
				case 'group_by':
					$query .= ' GROUP BY ' . implode( ',',$data );
				break;
				case 'having':
					$query .= " HAVING {$data}";
				break;
				case 'limit':
					$query .= " LIMIT {$data['start']}" . ( isset( $data['total'] ) ? ", {$data['total']}" : '' );
				break;
			}
		}
		return $query;
	}

	public function execute() {
		$sql = $this->build();
		if ( config::get('db.query_caching') === true && !is_null( $this->cache_expiration ) ) {
			$cache = new cache( path_get('storage-cache:db/queries') );
			$this->cache_id = md5( $sql );
			$cache->id( $this->cache_id );
			if ( ( $data = $cache->fetch( $this->cache_expiration['time'],$this->cache_expiration['type'] ) ) !== false ) {
				return $data;
			}
		}
		$result = $this->table->db()->execute( $sql,$this->table );
		if ( isset( $cache ) ) {
			$cache->set_data( $result );
		}
		return $result;
	}

}

?>