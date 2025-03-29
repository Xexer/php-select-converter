<?php
// https://help.sap.com/doc/abapdocu_752_index_htm/7.52/en-US/abapselect_shortref.htm

//### -------------------------------------------------------------------------------
//### LCL_SELECT_STATEMENT - SELECT Statement Converter
//### -------------------------------------------------------------------------------
class lcl_select_statement {
  const C_PART_SELECT = 'SELECT';
  const C_PART_FROM = 'FROM';
  const C_PART_FIELDS = 'FIELDS';
  const C_PART_WHERE = 'WHERE';
  const C_PART_INTO = 'INTO';
  const C_PART_GROUP = 'GROUP';
  const C_PART_ORDER = 'ORDER';
  const C_PART_HAVING = 'HAVING';
  const C_PART_UPTO = 'UP';
  
  const C_ATT_MAIN_TABLE = 'ATT:MAIN_TABLE';
  const C_ATT_WHERE_CLAUSE = 'ATT:WHERE_CLAUSE';
  const C_ATT_RESULT = 'ATT:RESULT_SET';
  const C_ATT_OLD = 'ATT:OLD_SELECT';
  const C_ATT_FIELDS = 'ATT:FIELDS';
  const C_ATT_JOINS = 'ATT:JOINS';
  const C_ATT_UPTO = 'ATT:UPTO';
  const C_ATT_ORDER = 'ATT:ORDER';
  const C_ATT_SELECT_SINGLE = 'SINGLE';
  const C_ATT_FOR_UPDATE = 'FOR UPDATE';
  const C_ATT_DISTINCT = 'DISTINCT';
  
  const C_JOIN_DELIMITER = '~';
  const C_ALIAS = 'AS';
  const C_JOIN = 'JOIN';
  const C_LEFT = 'LEFT';
  const C_RIGHT = 'RIGHT';
  const C_ON = 'ON';
  const C_BY = 'BY';
  
  private string $used_scenario = '';
  private string $original_statement = '';
  private array $elements = [];
  private ?lhc_result $object_result = NULL;


  function __construct(string $scenario) {
    $this->elements = [
      self::C_ATT_SELECT_SINGLE => false,
      self::C_ATT_FOR_UPDATE => false,
      self::C_ATT_DISTINCT => false,
      self::C_ATT_OLD => false,
      self::C_ATT_MAIN_TABLE => new lhc_table(),
      self::C_ATT_JOINS => [],
      self::C_ATT_FIELDS => [],
      self::C_ATT_WHERE_CLAUSE => [],
      self::C_ATT_RESULT => NULL,
      self::C_ATT_UPTO => NULL,
      self::C_ATT_ORDER => NULL,
    ];
    
    $this->object_result = new lhc_result();
    $this->used_scenario = $scenario;
  }


  public function check_statement(string $statement) : bool {
    $check_upper = strtoupper($statement);
    
    if (strpos($check_upper, 'SELECT') === false) {
      return false;
    }
    
    if (substr_count($check_upper, '.') !== 1) {
      return false;
    }
    cl_log::vari($statement);
    return true;
  }
  
  
  public function convert_statement(lhc_config $configuration) : lhc_result {
    $statement = $this->prepare_statement($configuration->statement);
    $keywords = explode(' ', $statement);

    $parts = [];
    $actual_part = '';
    
    foreach ($keywords as $keyword) {
      if (empty($keyword) || $keyword === "\n") {
        continue;
      }
      
      $check_keyword = strtoupper($keyword);
      
      switch ($check_keyword) {
        case self::C_PART_SELECT:
        case self::C_PART_FROM:
        case self::C_PART_FIELDS:
        case self::C_PART_WHERE:
        case self::C_PART_INTO:
        case self::C_PART_UPTO:
        case self::C_PART_GROUP:
        case self::C_PART_ORDER:
        case self::C_PART_HAVING:
          if ($check_keyword === self::C_PART_FIELDS && $actual_part === self::C_PART_INTO) {
            // Do not switch 
          } else {
            $actual_part = $check_keyword;
          }
          break;
      }
      
      if (isset($this->elements[$check_keyword])) {
        $this->elements[$check_keyword] = true;
        continue;
      }
      
      if (!isset($parts[$actual_part])) {
        $parts[$actual_part] = '';
      } else {
        $parts[$actual_part] .= ' '.$keyword;
      }
    }

    $this->reassemble_tables($parts[self::C_PART_FROM]);
    $this->reassemble_fields($parts);
    if (isset($parts[self::C_PART_INTO])) {
      $this->reassemble_result($parts[self::C_PART_INTO]);
    }
    if (isset($parts[self::C_PART_WHERE])) {
      $this->reassemble_where($parts[self::C_PART_WHERE]);
    }
    if (isset($parts[self::C_PART_UPTO])) {
      $this->reassemble_upto($parts[self::C_PART_UPTO]);
    }
    if (isset($parts[self::C_PART_ORDER])) {
      $this->reassemble_order($parts[self::C_PART_ORDER]);
    }
    
    $this->convert_tables_and_fields($configuration->abap_cloud);
    $this->assemble_statement();
    
    $this->log_scenario();
    return $this->object_result;
  }
  
  
  public function get_elements() : array {
    return $this->elements;
  }
  
  
  private function prepare_statement(string $statement) : string {
    $this->original_statement = $statement;
    
    $result = $statement;
    $result = $this->remove_inline_comments($result);
    $result = str_replace("\n", ' ', $result);
    $result = str_replace(".", '', $result);
    
    return $result;
  }
  
  
  private function remove_inline_comments(string $statement) : string {
    $result = '';
    $splitted = explode("\n", $statement);
    
    foreach ($splitted as $line) {
      $found_at = strpos($line, '"');
      if ($found_at !== false) {
        $line = substr($line, 0, $found_at);
      }
      
      $found_at = strpos($line, '&quot;');
      if ($found_at !== false) {
        $line = substr($line, 0, $found_at);
      }      
      
      $result .= "\n".$line;
    }         

    return $result;
  }
  
  
  private function log_scenario() {
    if (empty($this->used_scenario)) {
      return;
    }
    
    cl_tool_usage::create()->add($this->used_scenario);
  }
  
  
  private function reassemble_tables(string $from_clause) {
    $parts = explode(' ', $from_clause);
    $table = $this->elements[self::C_ATT_MAIN_TABLE];
    
    $alias = false;
    $where = NULL;
    
    foreach ($parts as $part) {
      if ($part === lhc_table::C_JOIN_INNER || $part === lhc_table::C_JOIN_LEFTO || $part === lhc_table::C_JOIN_RIGHTO) {
        $table = new lhc_table();
        $table->join_type = $part;
        $this->elements[self::C_ATT_JOINS][] = $table;
        $where = NULL;
        continue;
      }
      
      if ($part === self::C_JOIN || $part === self::C_LEFT || $part === self::C_RIGHT) {
        continue;
      }

      if ($part === self::C_ON) {
        $where = new lhc_where();
        continue;
      }      
      
      if ($where !== NULL) {
        $where = $this->build_where($where, $part, $table);
        continue;
      }
      
      if (strtoupper($part) === self::C_ALIAS) {
        $alias = true;
        continue;
      }
      
      if ($alias) {
        $alias = false;
        $table->alias = strtolower($part);
        continue;
      }
      
      $table->table_name = $part;
    }
  }
  
  
  private function reassemble_fields(array $it_parts) : void {
    $local = '';
    
    if (isset($it_parts[self::C_PART_FIELDS])) {
      $local = $it_parts[self::C_PART_FIELDS];
    } else {
      $this->elements[self::C_ATT_OLD] = true;
      $local = $it_parts[self::C_PART_SELECT];
    }
    
    $fields = explode(' ', $local);
    $alias = false;
    
    foreach ($fields as $field) {
      if (strtoupper($field) === self::C_ALIAS) {
        $ld_alias = true;
        continue;
      }
      
      if ($alias) {
        $alias = false;
        $last_field->alias = strtolower($field);
        continue;
      }
      
      $field = str_replace(',', '', $field);
      $field = trim($field);
      if (empty($field)) {
        continue;
      }
      
      $new_field = new lhc_field();
      
      if (str_contains($field, self::C_JOIN_DELIMITER) === true) {
        $splitted = explode(self::C_JOIN_DELIMITER, $field);
        $new_field->alias = strtolower($splitted[0]);
        $new_field->field_name = $splitted[1];
        
      } else {
        $new_field->field_name = $field;
        
      }
      
      $this->elements[self::C_ATT_FIELDS][] = $new_field;
      $last_field = $new_field;
    }
  }
  
  
  private function reassemble_result(string $into) : void {
    $into_clause = new lhc_into();
    $into_clause->into_clause = $into;
    $this->elements[self::C_ATT_RESULT] = $into_clause;
  }
  
  
  private function reassemble_where(string $where_string) : void {
    $parts = explode(' ', $where_string);
    $where_clause = new lhc_where();
    
    foreach ($parts as $part) {
      if (empty($part)) {
        continue;
      }
      
      $where_clause = $this->build_where($where_clause, $part, NULL);
    }
  }
  
  
  private function reassemble_upto(string $up_to) : void {
    $upto_clause = new lhc_upto();
    $upto_clause->up_to = $up_to;
    
    $this->elements[self::C_ATT_UPTO] = $upto_clause;
  }
  
  
  private function reassemble_order(string $order_clause) : void {
    $order = new lhc_order();
    
    if (str_contains($order_clause, 'PRIMARY KEY')) {
      $order->primary_key = true;
      $this->elements[self::C_ATT_ORDER] = $order; 
      return;
    }

    $fields = explode(' ', $order_clause);

    foreach ($fields as $field) {
      if (strtoupper($field) === self::C_BY) {
        continue;
      }
      
      if (strtoupper($field) === lhc_order_field::C_ORDER_ASC || strtoupper($field) === lhc_order_field::C_ORDER_DESC) {
        $last_field->order = strtoupper($field);
        continue;
      }
      
      $field = str_replace(',', '', $field);
      $field = trim($field);
      if (empty($field)) {
        continue;
      }
      
      $new_field = new lhc_order_field();
      
      if (str_contains($field, self::C_JOIN_DELIMITER) === true) {
        $splitted = explode(self::C_JOIN_DELIMITER, $field);
        $new_field->field->alias = strtolower($splitted[0]);
        $new_field->field->field_name = $splitted[1];
        
      } else {
        $new_field->field->field_name = $field;
        
      }
      
      $order->order_fields[] = $new_field;
      $last_field = $new_field;
    }

    $this->elements[self::C_ATT_ORDER] = $order;    
  }
  
  
  private function assemble_statement() : void {
    $assembler = new lcl_statement_assembler();
    $new_statement = $assembler->create_select_statement($this->elements);
    
    $this->object_result->new_statement = $new_statement;
    $this->object_result->old_statement = $this->original_statement;
  }
  
  
  private function build_where(lhc_where $where, string $part, ?lhc_table $table) : lhc_where {
    $where_clause = $where;
    $local_part = $part;
    
    // 0) Zwischenwerte
    if ($where_clause->is_between($local_part)) {
      $where_clause->between = $local_part;
      if ($table === NULL) {
        $this->elements[self::C_ATT_WHERE_CLAUSE][] = $where_clause;
      } else {
        $table->join_fields[] = $where_clause;
      }
      $where_clause = new lhc_where();
      
    // 1) Feld setzen
    } else if (empty($where_clause->field_name)) {
      if (str_contains($local_part, self::C_JOIN_DELIMITER) === true) {
        $splits = explode(self::C_JOIN_DELIMITER, $local_part);
        $where_clause->alias = strtolower($splits[0]);
        $where_clause->field_name = $splits[1];
        
      } else {
        $where_clause->field_name = $local_part;
        
      }
      
    // 2) Operator setzen
    } else if ($where_clause->is_operator($local_part)) {
      if (empty($where_clause->operator)) {
        $where_clause->operator = $local_part;
      } else {
        $where_clause->operator .= ' '.$local_part;
      }
      
    // 3) Wert setzen und neues Feld  
    } else {
      $where_clause->value = $local_part;
      if ($table === NULL) {
        $this->elements[self::C_ATT_WHERE_CLAUSE][] = $where_clause;
      } else {
        $table->join_fields[] = $where_clause;
      }
      $where_clause = new lhc_where();        
      
    }
    
    return $where_clause;
  } 
  
  
  private function convert_tables_and_fields(bool $abap_cloud) : void {
    $tables = [];
    $full_name = [];
    $mapping = new lcl_mapping($abap_cloud);

    // Tabellen mappen      
    $table = $this->elements[self::C_ATT_MAIN_TABLE];
    $cds_name = $this->get_cds_table($mapping, $table->table_name);
    $tables[$table->alias] = $cds_name;
    $tables[$table->table_name] = $cds_name;
    $full_name[$table->table_name] = $cds_name;
    $table->table_name = $cds_name;
    
    foreach ($this->elements[self::C_ATT_JOINS] as $join) {
      $cds_name = $this->get_cds_table($mapping, $join->table_name);
      $tables[$join->alias] = $cds_name;
      $tables[$join->table_name] = $cds_name;
      $full_name[$join->table_name] = $cds_name;
      $join->table_name = $cds_name;
    }
    
    // Felder mappen
    foreach ($this->elements[self::C_ATT_JOINS] as $join) {
      foreach ($join->join_fields as $where) {
        $where->field_name = $this->get_cds_field($mapping, $where->alias, $where->field_name, $tables);
        $where->alias = $this->get_alias_for_full_name($where->alias, $full_name);
        
        if (!str_contains($where->value, self::C_JOIN_DELIMITER)) {
          continue;
        }

        $splitted = explode(self::C_JOIN_DELIMITER, $where->value);
        $value_alias = $splitted[0];
        $value_name = $splitted[1];

        $where->value = $this->get_cds_field($mapping, $value_alias, $value_name, $tables);
        $value_alias = $this->get_alias_for_full_name($value_alias, $full_name);
        $where->value = $value_alias.self::C_JOIN_DELIMITER.$where->value;
      }
    }
    
    foreach ($this->elements[self::C_ATT_WHERE_CLAUSE] as $where) {
      $where->field_name = $this->get_cds_field($mapping, $where->alias, $where->field_name, $tables);
      $where->alias = $this->get_alias_for_full_name($where->alias, $full_name);
    }    
    
    foreach ($this->elements[self::C_ATT_FIELDS] as $field) {
      $field->field_name = $this->get_cds_field($mapping, $field->alias, $field->field_name, $tables);
      $field->alias = $this->get_alias_for_full_name($field->alias, $full_name);
    }      
    
    if (isset($this->elements[self::C_ATT_ORDER])) {
      foreach ($this->elements[self::C_ATT_ORDER]->order_fields as $order_field) {
        $order_field->field->field_name = $this->get_cds_field($mapping, $order_field->field->alias, $order_field->field->field_name, $tables);
        $order_field->field->alias = $this->get_alias_for_full_name($order_field->field->alias, $full_name);
      }
    }
  }
  
  
  private function get_alias_for_full_name(string $alias, array $full_name) : string {
    if (isset($full_name[$alias])) {
      return $full_name[$alias];
    } else {
      return $alias;   
    }
  }
  
  
  private function get_cds_table(lcl_mapping $io_mapping, string $id_name) : string {
    $new_name = $io_mapping->map_table(strtoupper($id_name));
    
    if (empty($new_name)) {
      $this->object_result->add_error(10240, $id_name);
      return $id_name;
    } else {
      return $new_name;
    }
  } 
  

  private function get_cds_field(lcl_mapping $mapping, string $alias, string $name, array $tables) : string {
    if ($name === '*' || empty($name)) {
      return $name;
    }
    
    $cds_name = $tables[$alias];
    $new_name = $mapping->map_field(strtoupper($cds_name), strtoupper($name));
    
    if (empty($new_name)) {
      $this->object_result->add_error(10241, $name);
      return $name;
    } else {
      return $new_name;
    }
  }   
}

//### -------------------------------------------------------------------------------
//### LCL_STATEMENT_ASSEMBLER - Build new statement
//### -------------------------------------------------------------------------------
class lcl_statement_assembler {
  private string $statement_buffer = '';
  
  
  public function create_select_statement(array $configuration) : string {
    $this->clear_buffer();
    $this->add_select_start($configuration);
    $this->add_select_single($configuration);
    $this->add_select_table($configuration);
    $this->add_select_join($configuration);
    $this->add_select_fields($configuration);
    $this->add_select_where($configuration);
    $this->add_select_order($configuration);
    $this->add_select_into($configuration);
    $this->add_select_upto($configuration);
    $this->add_select_end($configuration);
    
    return $this->statement_buffer;
  }
  
  
  private function clear_buffer() : void {
    $this->statement_buffer = '';
  }
  
  
  private function add_break(int $spaces) : string {
    return str_pad("\n", $spaces + 1);
  }
  
  
  private function add_select_start(array $configuration) : void {
    $this->statement_buffer = 'SELECT ';
  }
  
  
  private function add_select_single(array $configuration) : void {
    if ($configuration[lcl_select_statement::C_ATT_SELECT_SINGLE] === false) {
      return;
    }
    
    $this->statement_buffer .= 'SINGLE ';
  }
  
  
  private function add_select_table(array $configuration) : void {
    $table = $configuration[lcl_select_statement::C_ATT_MAIN_TABLE];
    
    $this->statement_buffer .= 'FROM '.$table->get_table_name();
  }
  
  
  private function add_select_join(array $configuration) : void {
    $joins = $configuration[lcl_select_statement::C_ATT_JOINS];
    
    foreach ($joins as $table) {
      $this->statement_buffer .= $this->add_break(2).$table->get_table_name().$this->add_break(5)."ON ";
      
      foreach ($table->join_fields as $where) {
        $this->statement_buffer .= $where->get_compare(true).' ';
      }
    }
  }
  
  
  private function add_select_fields(array $configuration) : void {
    $fields = $configuration[lcl_select_statement::C_ATT_FIELDS];
    
    if (count($fields) === 0) {
      return;
    }
    
    $this->statement_buffer .= $this->add_break(2).'FIELDS ';
    
    $ld_first_field = true;
    $break_line = -1;
    
    foreach ($fields as $field) {
      if ($ld_first_field) {
        $ld_first_field = false;
      } else {
        $this->statement_buffer .= ', ';
      }
      
      if ($break_line === 3) {
        $this->statement_buffer .= $this->add_break(4);
        $break_line = 0;
      } else {
        $break_line += 1;
      }
      
      $this->statement_buffer .= $field->get_field_name();
    }
  }
  
  
  private function add_select_where(array $configuration) : void {
    $where_clauses = $configuration[lcl_select_statement::C_ATT_WHERE_CLAUSE];
    
    if (count($where_clauses) === 0) {
      return; 
    }
    
    $this->statement_buffer .= $this->add_break(2).'WHERE ';
    
    foreach ($where_clauses as $where) {
      $this->statement_buffer .= $where->get_compare(false).' ';
    }
  }
  
  
  private function add_select_order(array $configuration) : void {
    $order_by = $configuration[lcl_select_statement::C_ATT_ORDER];
    
    if ($order_by === NULL) {
      return;
    }        
    
    $this->statement_buffer .= $this->add_break(2).'ORDER BY '.$order_by->get_statement();
  }
  
  
  private function add_select_into(array $configuration) : void {
    $result_clause = $configuration[lcl_select_statement::C_ATT_RESULT];
    
    if ($result_clause === NULL) {
      return;
    }    
    
    $this->statement_buffer .= $this->add_break(2).'INTO'.$result_clause->get_into_clause();
  }  
  
  
  private function add_select_upto(array $configuration) : void {
    $upto_clause = $configuration[lcl_select_statement::C_ATT_UPTO];
    
    if ($upto_clause === NULL) {
      return;
    }
    
    $this->statement_buffer .= $this->add_break(2).'UP'.$upto_clause->get_statement();
  }  
  
  
  private function add_select_end(array $configuration) : void {
    $this->statement_buffer .= '.';
  }  
}

//### -------------------------------------------------------------------------------
//### LCL_MAPPING - Mapping der Werte und Tabellen
//### -------------------------------------------------------------------------------
class lcl_mapping {
  const C_MAPPING_GIT = 'https://raw.githubusercontent.com/Xexer/abap-cds-field-mapping/main/mapping/core-data-services.json';
  
  private array $mapping = [];
  private bool $is_loaded = false;
  private bool $abap_cloud = true;
  
  
  function __construct(bool $abap_cloud) {
    $this->abap_cloud = $abap_cloud;
  }
  
  
	public function map_table(string $table_name) : string {
	  $this->load_mapping();
	  
    foreach ($this->mapping as $mapping) {
      if ($mapping['released'] === false && $this->abap_cloud === true) {
        continue;
      }
      
      if ($mapping['tableName'] === $table_name) {
        return $mapping['cdsName'];
      }
    }  
    
    return '';
  }  
  
  
  public function map_field(string $cds_name, string $field_name) : string {
    $this->load_mapping();
    
    foreach ($this->mapping as $mappings) {
      if ($mappings['released'] === false && $this->abap_cloud === true) {
        continue;
      }
      
      if ($mappings['cdsName'] !== $cds_name) {
        continue;
      }
      
      foreach ($mappings['mapping'] as $field_mapping) {
        if ($field_mapping['tableField'] === $field_name) {
          return $field_mapping['cdsField'];
        }
      }      
    }     
    
    return '';
  }
  
  
  private function load_mapping() : void {
    if ($this->is_loaded) {
      return;
    }
    
    $lo_request = new cl_url_request(self::C_MAPPING_GIT);
    $this->mapping = $lo_request->get_json_as_array();
    $this->is_loaded = true;
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> Configuration Type
//### -------------------------------------------------------------------------------
class lhc_config {
  public string $statement = '';
  public bool $abap_cloud = false;
}

//### -------------------------------------------------------------------------------
//### Helper -> Result Type
//### -------------------------------------------------------------------------------
class lhc_result {
  public bool $success = false;
  public string $new_statement = '';
  public string $old_statement = '';
  public array $errors = [];
  
  
  public function has_error() : bool {
    if (count($this->errors) === 0) {
      return false;
    } else {
      return true;
    }
  }
  

  public function add_error(int $number, string $free_text) : void {
    $this->errors[] = cl_text::get($number).': '.$free_text;
  }    
}

//### -------------------------------------------------------------------------------
//### Helper -> Table
//### -------------------------------------------------------------------------------
class lhc_table {
  const C_JOIN_INNER = 'INNER';
  const C_JOIN_LEFTO = 'LEFT';
  const C_JOIN_RIGHTO = 'RIGHT';
  
  public string $table_name = '';
  public string $alias = '';
  public string $join_type = '';
  public array $join_fields = [];
  
  
  public function get_table_name() : string {
    $table_name = $this->table_name;
    
    if (!empty($this->alias)) {
      $table_name .= ' AS '.$this->alias;
    }
    
    if (!empty($this->join_type)) {
      $table_name = $this->get_join().' '.$table_name;
    }
    
    return $table_name;
  }
  
  
  private function get_join() : string {
    switch ($this->join_type) {
      case self::C_JOIN_INNER:
        return 'INNER JOIN';
      case self::C_JOIN_LEFTO:
        return 'LEFT OUTER JOIN';
      case self::C_JOIN_RIGHTO:
        return 'RIGHT OUTER JOIN';        
    }
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> Field
//### -------------------------------------------------------------------------------
class lhc_field {
  public string $field_name = '';
  public string $alias = '';
  public string $table = '';

  public function get_field_name() : string {
    if (empty($this->alias)) {
      return $this->field_name;
    } else {
      return $this->alias.'~'.$this->field_name;
    }
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> Where Clause
//### -------------------------------------------------------------------------------
class lhc_where {
  public string $field_name = '';
  public string $alias = '';
  public string $operator = '';
  public string $value = '';
  public string $between = '';
  
  
  public function get_compare(bool $is_join) : string {
    if (!empty($this->between)) {
      $between = $this->between;
      
      if ($this->is_delimiter($between)) {
        $between = "\n    ".$between;
      }
      
      return $between;
    }
    
    $final_part = $this->get_field();
    $final_part .= ' '.$this->map_operator();
    $final_part .= ' '.$this->get_value($is_join);
    return $final_part;
  }
  
  
  public function is_operator(string $operator) : bool {
    $operators = [
      'IN' => '', 
      '=' => '', 
      '>=' => '', 
      '<=' => '', 
      '>' => '', 
      '<' => '', 
      '<>' => '', 
      '&gt;=' => '', 
      '&lt;=' => '', 
      '&gt;' => '', 
      '&lt;' => '', 
      '&lt;&gt;' => '',       
      'EQ' => '', 
      'NE' => '', 
      'GE' => '', 
      'GT' => '', 
      'LE' => '', 
      'LT' => '', 
      'BT' => '', 
      'BETWEEN' => '', 
      'IS' => '', 
      'NOT' => '', 
      'INITIAL' => '', 
      'NULL' => '', 
    ];    
    
    if (isset($operators[$operator])) {
      return true;
    } else {
      return false;
    }
  }
  
  
  public function is_between(string $operator) : bool {
    $operators = [
      'AND' => '', 
      'OR' => '', 
      '(' => '', 
      ')' => '', 
    ];    
    
    if (isset($operators[$operator])) {
      return true;
    } else {
      return false;
    }
  }  
  
  
  private function is_delimiter(string $operator) : bool {
    $operators = [
      'AND' => '', 
      'OR' => '',
    ];    
    
    if (isset($operators[$operator])) {
      return true;
    } else {
      return false;
    }
  }  
  
  
  private function map_operator() : string {
    $operator = strtoupper($this->operator);
    
    switch ($operator) {
      case 'EQ':
        return '=';
      case 'NE':
        return '<>';        
      case 'GE':
        return '>=';
      case 'GT':
        return '>';
      case 'LE':
        return '<=';    
      case 'LT':
        return '<';        
      default:
        return $operator;
    }
  }
  
  
  private function get_field() : string {
    if (empty($this->alias)) {
      return $this->field_name;
    } else {
      return $this->alias.'~'.$this->field_name;
    }    
  }
  
  
  private function get_value(bool $is_join) : string {
    if (str_contains($this->value, "'") === true || str_contains($this->value, "&#039;") === true) {
      return $this->value;
    }
    
    if (str_contains($this->value, "@") === false and $is_join === false) {
      return '@'.$this->value;
    }
    
    return $this->value;
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> Into Clause
//### -------------------------------------------------------------------------------
class lhc_into {
  public string $into_clause = '';
  
  
  public function get_into_clause() : string {
    $this->check_last_variable();
    
    return $this->into_clause;
  }
  
  
  private function check_last_variable() : void {
    $splitted = explode(' ', $this->into_clause);
    $last_part = $splitted[count($splitted) - 1];
    
    if (str_contains($last_part, "@") === false) {
      $this->into_clause = str_replace($last_part, '@'.$last_part, $this->into_clause);
    }    
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> UP TO Clause
//### -------------------------------------------------------------------------------
class lhc_upto {
  public string $up_to = '';
  
  
  public function get_statement() : string {
    return $this->up_to;
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> Order By
//### -------------------------------------------------------------------------------
class lhc_order {
  public bool $primary_key = false;
  public array $order_fields = [];
  
  
  public function get_statement() : string {
    $statement = '';
    
    if ($this->primary_key) {
      $statement .= 'PRIMARY KEY '; 
    } else {
      foreach ($this->order_fields as $field) {
        if (!empty($statement)) {
          $statement .= ', ';
        }
        
        $statement .= $field->get_field_name(); 
      }
    }
    
    return $statement;
  }
}

//### -------------------------------------------------------------------------------
//### Helper -> Order By Field
//### -------------------------------------------------------------------------------
class lhc_order_field {
  const C_ORDER_ASC = 'ASCENDING';
  const C_ORDER_DESC = 'DESCENDING';
  
  public ?lhc_field $field = NULL;
  public string $order = '';
  
  
  public function __construct() {
    $this->field = new lhc_field();
  }
  
  public function get_field_name() : string {
    return $this->field->get_field_name().' '.$this->order;
  }
}
?>