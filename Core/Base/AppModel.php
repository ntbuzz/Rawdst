<?php
/* -------------------------------------------------------------
 * Object Oriented PHP MVC Framework
 *  AppModel:    Database IN/OUT abstraction Class
 *              Handle of SQLite3, PostgreSQL, MariaDB
 */
require_once('Core/Handler/DatabaseHandler.php');

//==============================================================================
class AppModel extends AppObject {
    static $DatabaseSchema = [
        'Handler' => 'Null',
        'DataTable' => '',
        'Primary' => '',
        'LoginID' => '',
        'Schema' => [],
        'PostRenames' => [],
    ];
    protected $dbDriver;            // Database Driver
    protected $fields;              // Record-Data all fields value
    public $pagesize = 0;           // get record count per PAGE
    public $page_num = 0;           // get Page Number
    public $record_max = 0;         // Total records count
    public $row_number = 0;         // near record in target record row
    public $AliasMode = TRUE;       // Language Alias Enable

    public $RecData = NULL;          // ROW record data (no JOIN)
    public $Select = NULL;           // Select List for relation table field
    public $Records = NULL;          // get records lists (with JOIN field)
    public $OnGetRecord = NULL;      // for feature FUNCTION
    public $HeaderSchema = [];       // Display Header List [ field_name => [disp_name, align, sort_flag ]
    private $FieldSchema = [];       // Pickup Record fields columns [ref_name, org_name]
    private $Relations = [];         // Table Relation
    public $DateFormat;              // Date format for Database
    public $SortDefault = SORTBY_ASCEND;    // findRecord Default Sort Sequence
//==============================================================================
//	Constructor: Owner
//==============================================================================
	function __construct($owner) {
	    parent::__construct($owner);                    // call parent constructor
        $this->setProperty(static::$DatabaseSchema);    // Set Propert from Database Schema Array
        if(isset($this->ModelTables)) {                 // Multi-Language Tabele exists
            $db_key = (array_key_exists(LangUI::$LocaleName,$this->ModelTables)) ? LangUI::$LocaleName : '*';
            $this->DataTable = $this->ModelTables[$db_key]; // DataTable SWITCH
        }
        $this->__InitClass();
        $this->fields = [];
	}
//==============================================================================
// Initializ Class Property
    protected function __InitClass() {
        $driver = $this->Handler . 'Handler';
        $this->dbDriver = new $driver($this->DataTable);        // connect Database Driver
        $this->DateFormat = $this->dbDriver->DateStyle;         // Date format from DB-Driver
        $this->SchemaAnalyzer($this->Schema);                   // Schema Initialize
        parent::__InitClass();
    }
//==============================================================================
// Switch Schema Language
public function ResetSchema() {
    $this->SchemaAnalyzer();
    $this->RelationSetup();
}
//==============================================================================
// extract DataTable or Alternate DataView
    private function model_view($db) {
        list($model,$field,$refer) = explode('.', "{$db}...");
        if(preg_match('/(\w+)(?:\[(\d+)\])/',$model,$m)===1) {
            $model = $m[1];
            $table = (is_array($this->$model->DataView))
                        ? $this->$model->DataView[$m[2]]            // View Element Index
                        : $this->$model->dbDriver->table;                 // illegal define
        } else $table = $this->$model->dbDriver->table;
        return [$model,$table,$field,$refer];
    }
//==============================================================================
// Table Relation setup DBMSG_MODEL
public function RelationSetup($debug_log = DBMSG_MODEL) {
    $new_Relations = [];
    foreach($this->Relations as $key => $rel) {
        $base_name = (substr($key,-3)==='_id') ? substr($key,0,strlen($key)-3) : $key;
        $rel_array = is_array($rel);
        if($rel_array) {
            list($db,$ref_list) = array_first_item($rel);
            if(is_numeric($db)) continue;
            list($model,$table,$field) = $this->model_view($db);
            $link = "{$table}.{$field}";
            if(is_scalar($ref_list)) $ref_list = [$ref_list];    // force array
        } else {
            list($model,$table,$field,$refer) = $this->model_view($rel);
            $link = "{$table}.{$field}";
            $ref_list = [ $refer ];
        }
        $sub_rel = [];
        foreach($ref_list as $refer) {
            $sub_ref = $this->$model->JoinDefinition($table,$refer);
            $alias_name = ($rel_array) ? "{$base_name}_{$refer}" : $base_name;
            $this->FieldSchema[$alias_name] = NULL;   // $key; import MUST!
            // locale if exist in relation-db
            if($this->$model->dbDriver->fieldAlias->exists_locale($refer)) {
                $ref_name = "{$refer}_" . LangUI::$LocaleName;
            } else $ref_name = $refer;
            $sub_rel[$alias_name] = (is_array($sub_ref)) ? $sub_ref : "{$link}.{$ref_name}";
        }
        $new_Relations[$key] =  $sub_rel;
    }
    $this->dbDriver->setupRelations($new_Relations);
    $this->SelectionSetup();
    debug_log($debug_log,[             // DEBUG LOG information
//        "Schema" => $this->Schema,
        "Header" => $this->HeaderSchema,
//        "Field" => $this->FieldSchema, 
        "JOIN-definition" => $new_Relations,
        "Locale-Bind" => $this->dbDriver->fieldAlias->GetAlias(),
        "SELECTDEFS" => $this->Selection,
    ]);
}
//==============================================================================
// nested relation model
protected function JoinDefinition($table,$field) {
    if(is_array($this->dbDriver->relations)) {
        foreach($this->dbDriver->relations as $key => $refs) {
            if(array_key_exists($field,$refs)) {
                $lnk = explode('.',$refs[$field]);
                return [
                    $lnk[0],                        // table
                    "{$lnk[0]}.\"{$lnk[2]}\"",      // table."ref"
                    "{$table}.\"{$key}\"",          // rel_table.id
                    "{$lnk[0]}.\"{$lnk[1]}\"",      // table.id
                ];
            }
        }
    }
    return $field;
}
//==============================================================================
// Selection Table Relation setup
//   Selection => [
//      'os_select'      => [ 'Os.id' => [ 'name', 'os_id' ] ,[ cond ] ],
//      'os_select_id'   => [ 'Os.id' => 'name.os_id' ,[ cond ] ],
//       'lisece_select' => [ 'id','license','operating_system_id',[ cond ] ],
//   ]
public function SelectionSetup() {
    $new_Selection = [];
    if(isset($this->Selection)) {
        $separate_rel_cond = function($defs) {
            $rel = $cond = [];
            foreach($defs as $key => $val) {
                if(is_int($key)) {
                    if(is_array($val)) $cond = $val;
                    else $rel[] = $val;
                } else {
                    $rel[$key] = $val;
                }
            }
            return [$rel,$cond];
        };
        foreach($this->Selection as $key_name => $seldef) {
            $lnk = [];
            if(is_scalar($seldef)) {
                $lnk[0] = $seldef;
                $cond = [];
            } else {
                list($target,$cond) = $separate_rel_cond($seldef);
                list($model,$ref_list) = array_first_item($target);
                if($model === 0) {
                    $lnk[0] = $target;
                } else {
                    list($model,$table,$field) = $this->model_view($model);
                    if(is_scalar($ref_list)) {
                        $lnk = [ $model => "{$field}.{$ref_list}"] ;
                    } else {
                        array_unshift($ref_list,$field);
                        $lnk[$model] = $ref_list;
                    }
                }
            }
            $new_Selection[$key_name] =  [$lnk,$cond];
        }
    }
    $this->Selection = $new_Selection;
}
//==============================================================================
// Selection Table Relation setup
//   Selection => [
//      'os_select'      => [ 'Os.id' => [ 'name', 'os_id' ] ],
//      'os_select_id'   => [ 'Os.id' => 'name.os_id'  ],
//       'lisece_select' => [ 'id','license','operating_system_id' ],
//   ]
public function SelectionSetup() {
    $new_Selection = [];
    if(isset($this->Selection)) {
    foreach($this->Selection as $key_name => $seldef) {
        $lnk = [];
        if(is_scalar($seldef)) $lnk[0] = $seldef;
        else {
            list($model,$ref_list) = array_first_item($seldef);
            if($model === 0) {
                $lnk[0] = $seldef;
            } else {
                list($model,$table,$field) = $this->model_view($model);
                if(is_scalar($ref_list)) {
                    $lnk = [ $model => "{$field}.{$ref_list}"] ;
                } else {
                    array_unshift($ref_list,$field);
                    $lnk[$model] = $ref_list;
                }
            }
        }
        $new_Selection[$key_name] =  $lnk;
    }
    }
    $this->Selection = $new_Selection;
}
//==============================================================================
// Schema Define Analyzer
    protected function SchemaAnalyzer() {
        $header = $relation = $locale = $bind = $field = [];
        foreach($this->Schema as $key => $defs) {
            array_push($defs,0,NULL,NULL);
            $ref_key = $key;
            list($disp_name,$disp_flag,$width,$relations,$binds) = $defs;
            list($accept_lang,$disp_align,$disp_head) = [intdiv($disp_flag,100),intdiv($disp_flag%100,10), $disp_flag%10];
            if(!empty($relations)) {
                if(substr($key,-3)==='_id' && is_scalar($relations)) {
                    $ref_key = substr($key,0,strlen($key)-3);
                    $field[$key] = $key;        // setup name before _id cut-out
                }
                $relation[$key] = $relations;
            }
            if(!empty($binds)) {
                $bind[$ref_key] = $binds;
                $key = NULL;
            }
            $field[$ref_key] = $key;
            if($disp_head !== 0) {
                if(empty($disp_name)) $disp_name = $ref_key;
                else if($disp_name[0] === '.') $disp_name = $this->_(".Schema{$disp_name}");
                $header[$ref_key] = [$disp_name,$disp_align,$disp_head,$width];
            }
            if($accept_lang) {
                $ref_name = "{$ref_key}_" . LangUI::$LocaleName;
                if(array_key_exists($ref_name,$this->dbDriver->columns)) {
                    $locale[$ref_key] = $ref_name;
                }
            }
        }
        $this->HeaderSchema = $header;
        $this->FieldSchema = $field;
        $this->Relations = $relation;
        $this->dbDriver->fieldAlias->SetupAlias($locale,$bind);
    }
//==============================================================================
// Paging Parameter Setup
public function SetPage($pagesize,$pagenum) {
    $this->pagesize = $pagesize;
    $this->page_num = ($pagenum <= 0) ? 1 : $pagenum;
    $this->dbDriver->SetPaging($this->pagesize,$this->page_num);
}
//==============================================================================
// Get ROW-RECORD by Primarykey
// Result:   $this->fields in Column Data
public function getRecordByKey($id) {
    return $this->getRecordBy($this->Primary,$id);
}
//==============================================================================
// Get ROW-RECORD by Field Name
// Result:   $this->fields in Column Data
public function getRecordBy($key,$value) {
    if(!empty($value)) {
        $row = $this->dbDriver->doQueryBy($key,$value);
        $this->fields = ($row === FALSE) ? [] : $row;
    } else $this->fields = array();
    return $this->fields;
}
//==============================================================================
// Get Record Data by primary-key,and JOIN data by $join is TRUE.
// Result:   $this->RecData in Column Data
public function GetRecord($num,$join=FALSE) {
    if($join) {
        if(empty($num)) $this->fields = array();
        else $this->fields = $this->dbDriver->getRecordValue([$this->Primary => $num],TRUE);
    } else $this->getRecordBy($this->Primary,$num);
    $this->RecData= $this->fields;
}
//==============================================================================
//   Get Relation Table fields data list.
// Result:   $this->Select (Relations)
/*
public function GetValueList() {
    $valueLists = array();
    $this->dbDriver->getValueLists_callback(function($key,$val_list) use (&$valueLists) {
        $valueLists[$key] = $val_list;
    });
    $this->Select= $valueLists;
    debug_log(DBMSG_MODEL, [ "SELECT_LIST" => $this->Select]);
}
*/
//==============================================================================
//   Get Relation Table fields data list.
// Result:   $this->Select (Relations)
public function GetValueList() {
    $valueLists = array();
    $filter_rec = function($record,$filter) {
        $new = [];
        foreach($filter as $key) $new[$key] = $record[$key];
        return $new;
    };
    foreach($this->Selection as $key_name => $seldef) {
        list($target,$cond) = $seldef;
        list($model,$ref_list) = array_first_item($target);
        if(is_numeric($model)) {        // self list
            $this->RecordFinder($cond,$ref_list,NULL,$filter_rec);
            $valueLists[$key_name] = $this->Records;
        } else if(is_array($ref_list)) {
            $this->$model->RawRecordFinder($cond,$ref_list,NULL,$filter_rec);
            $valueLists[$key_name] = $this->$model->Records;
        } else {
            $ref_list = explode('.', $ref_list);
            $postfix = (count($ref_list) > 2);      // append to keyname_field
            $ref_list = array_filter( $ref_list, "strlen" ) ;
            $new_rec = [];
            $this->$model->RecordFinder($cond,$ref_list,NULL,function($record,$filter) use(&$new_rec) {
                $id = array_shift($filter);
                foreach($filter as $key) {
                    $rec_key = $record[$key];
//                    if(!empty($rec_key)) 
                    $new_rec[$key][$rec_key] = $record[$id];
                }
                return [];
            });
            array_shift($ref_list);     // remove relation-id
            foreach($ref_list as $key) {
                $key_set = ($postfix) ? "{$key_name}_{$key}" : $key_name;
                $valueLists[$key_set] = $new_rec[$key];
            }
        }
    }
    $this->Select= $valueLists;
    debug_log(DBMSG_MODEL, [ "SELECT_LIST" => $this->Select]);
}
//==============================================================================
//   Get Field Value List
// Result:   $this->Select (Field)
public function GetFieldValues($field) {
    $this->Select[$field] = $this->dbDriver->getValueLists(NULL,$field,$field);
    debug_log(DBMSG_MODEL, [ "VALUE_LIST" => $this->Select]);
}
//==============================================================================
// Get Record Field by field-key without JOIN fields.
// Result:   field-data
public function getRecordField($key,$value,$field) {
    $this->getRecordBy($key,$value);
    return (array_key_exists($field,$this->fields)) ? $this->fields[$field] : NULL;
}
//==============================================================================
// 条件に一致するレコード数を検索する
public function getCount($cond) {
    return $this->dbDriver->getRecordCount($cond);
}
//==============================================================================
// Get Record List by FIND-CONDITION with JOIN Table.
// Result:   $this->Records  Find-Result List
public function RecordFinder($cond,$filter=NULL,$sort=NULL,$callback=NULL) {
    if(empty($filter)) $filter = $this->dbDriver->columns;
    $fields_list = array_filter($this->FieldSchema, function($vv) use (&$filter) {
        return in_array($vv,$filter,true) || ($vv === NULL);
    });
    $data = array();
    if(empty($sort)) $sort = [ $this->Primary => $this->SortDefault ];
    else if(is_scalar($sort)) {
        $sort = [ $sort => $this->SortDefault ];
    }
    $this->dbDriver->findRecord($cond,TRUE,$sort);
    while (($fields = $this->dbDriver->fetchDB())) {
        unset($record);
        foreach($fields_list as $key => $val) {
            $record[$key] = $fields[$key];
            if($val !== NULL && $key !== $val) $record[$val] = $fields[$val];
        }
        // Must be PRIMARY-KEY
        $record[$this->Primary] = $fields[$this->Primary];
        if(! empty($record) ) {
            if($callback !== NULL) $record = $callback($record,$filter);
            $data[] = $record;
            $this->record_max = $this->dbDriver->recordMax;
        } else {
            debug_log(DBMSG_MODEL, ["fields" => $fields]);
        }
    }
    $this->Records = $data;
    debug_log(FALSE, [
        "record_max" => $this->record_max,
        "Filter" => $filter,
        "FieldSchema" => $this->FieldSchema,
        "FILTER" => $fields_list,
        "RECORDS" => $this->Records,
    ]);
}
//==============================================================================
// Get Raw Record List by FIND-CONDITION without JOIN!.
// Result:   $this->Records  Find-Result List
public function RawRecordFinder($cond,$filter=NULL,$sort=NULL,$callback=NULL) {
    if(empty($filter)) $filter = $this->dbDriver->columns;
    $fields_list = array_combine($filter,$filter);
    $fields_list[$this->Primary] = $this->Primary;  // must be include Primary-Key
    $data = array();
    if(empty($sort)) $sort = [ $this->Primary => $this->SortDefault ];
    else if(is_scalar($sort)) {
        $sort = [ $sort => $this->SortDefault ];
    }
    $this->dbDriver->findRecord($cond,NULL,$sort);
    while (($fields = $this->dbDriver->fetch_array())) {
        unset($record);
        foreach($fields_list as $key => $val) {
            $record[$key] = $fields[$key];
        }
        if(! empty($record) ) {
            if($callback !== NULL) $record = $callback($record,$filter);
            $data[] = $record;
            $this->record_max = $this->dbDriver->recordMax;
        } else {
            debug_log(DBMSG_MODEL, ["fields" => $fields]);
        }
    }
    $this->Records = $data;
    debug_log(FALSE, [
        "record_max" => $this->record_max,
        "Filter" => $filter,
        "FieldSchema" => $this->FieldSchema,
        "FILTER" => $fields_list,
        "RECORDS" => $this->Records,
    ]);
}
//==============================================================================
// Get First Record by condition w/o JOIN!
// Result:   $this->RecData
public function firstRecord($cond=[],$filter=NULL,$sort=NULL) {
    if(empty($filter)) $filter = $this->dbDriver->columns;
    $fields_list = array_combine($filter,$filter);
    $fields_list[$this->Primary] = $this->Primary;  // must be include Primary-Key
    $fields = $this->dbDriver->firstRecord($cond,FALSE,$sort);
    $this->RecData = array_filter($fields, function($val,$key) use (&$filter) {return in_array($key,$filter,true);},ARRAY_FILTER_USE_BOTH);
    debug_log(DBMSG_MODEL, [
        "Filter" => $filter,
        "RECORDS" => $this->RecData,
    ]);
}
//==============================================================================
// Get Record with Prev/Next Record List by FIND-CONDITION without JOIN!.
// Result:   $this->NearData  Find-Result List
public function NearRecordFinder($primary,$cond,$filter=NULL,$sort=NULL) {
    if(empty($filter)) $filter = $this->dbDriver->columns;
    $fields_list = array_combine($filter,$filter);
    foreach($this->FieldSchema as $key => $val) $fields_list[$key] = $val;
    $fields_list[$this->Primary] = $this->Primary;  // must be include Primary-Key
    if(empty($sort)) $sort = [ $this->Primary => $this->SortDefault ];
    else if(is_scalar($sort)) $sort = [ $sort => $this->SortDefault ];
    $this->dbDriver->findRecord($cond,TRUE,$sort);
    $r_prev = $r_next = $r_self = NULL;
    $prev = true;
    $row_num = 0;
    $primary = intval($primary);
    while (($fields = $this->dbDriver->fetchDB())) {
        $data = [];
        foreach($fields_list as $key => $val) $data[$key] = $fields[$key];
        $row_id = $fields[$this->Primary];
        if( intval($row_id) === $primary) {
            $prev = false;
            $r_self = $data;
            ++$row_num;
        } else if($prev) {
            $r_prev = $data;
            ++$row_num;
        } else {
            $r_next = $data;
            break;
        }
    }
    $this->row_number = $row_num;
    $this->record_max = $this->dbDriver->recordMax;
    $this->NearData = [$r_prev, $r_next ];
    $this->RecData = $r_self;
    debug_log(DBMSG_MODEL, [
        "Filter" => $filter,
        "RECDATA" => $this->RecData,
        "RECORDS" => $this->NearData,
    ]);
}
//==============================================================================
// Delete Record(Primary-key)
public function DeleteRecord($num) {
    $this->dbDriver->deleteRecord([$this->Primary => $num]);
}
//==============================================================================
// DELETE Records by FIND-CONDITION
public function MultiDeleteRecord($cond) {
    $this->dbDriver->deleteRecord($cond);
}
//==============================================================================
// VALIDATION of write data
public function is_valid(&$row) {
    return TRUE;
}
//==============================================================================
// field-alias, field-bind processing.
    private function field_alias_bind($row) {
        $this->fields = array();
        foreach($row as $key => $val) {
            $alias = ($this->AliasMode) ? $this->dbDriver->fieldAlias->get_lang_alias($key) :$key;
            $this->fields[$alias] = $val;
        }
        debug_log(DBMSG_MODEL,['ALIAS' => $this->fields]);
    }
//==============================================================================
// pickup on exist edit table database field
    private function field_pickup($row) {
        $data = array();
        foreach($row as $key => $val) {
            if(array_key_exists($key,$this->dbDriver->raw_columns)) {
                $data[$key] = $val;
            }
        }
        unset($data[$this->Primary]);
        return $data;
    }
//==============================================================================
// Add NEW record
public function AddRecord($row) {
    $data = $this->field_pickup($row);
    if($this->is_valid($data)) {
        $this->field_alias_bind($data);
        $this->dbDriver->insertRecord($this->fields);
    }
}
//==============================================================================
// UPDATE Record
public function UpdateRecord($num,$row) {
    $data = $this->field_pickup($row);
    if($this->is_valid($data)) {
        $this->field_alias_bind($data);
        $this->dbDriver->updateRecord([$this->Primary => $num],$this->fields);
    }
}

}
