<?php

namespace go\modules\core\customfields\model;

use Exception;
use GO;
use go\core\acl\model\AclItemEntity;
use go\core\db\Query;
use go\core\db\Table;
use go\core\db\Utils;
use go\core\ErrorHandler;
use go\core\orm\EntityType;
use go\modules\core\customfields\datatype\Base;
use go\modules\core\customfields\model\FieldSet;
use function GuzzleHttp\json_decode;
use function GuzzleHttp\json_encode;

class Field extends AclItemEntity {

	/**
	 * The Entity ID
	 * 
	 * @var int
	 */
	public $id;
	public $name;
	public $fieldSetId;
	public $sortOrder;
	protected $options;
	public $databaseName;
	public $required;
	public $hint;
	public $prefix;
	public $suffix;
	public $type;
	public $modifiedAt;
	public $createdAt;
	private $default;
	private $defaultModified = false;
	private $unique;
	private $uniqueModified = false;

	protected static function defineMapping() {
		return parent::defineMapping()->addTable('core_customfields_field', 'f');
	}

	protected static function aclEntityClass() {
		return FieldSet::class;
	}

	protected static function aclEntityKeys() {
		return ['fieldSetId' => 'id'];
	}

	/**
	 * LEGACY. $field->multiselect is used many times.
	 * fix before removing a property
	 */
	public function getMultiselect() {
		return $this->getOptions('multiselect');
	}

	public function getOptions() {
		return empty($this->options) ? [] : json_decode($this->options, true);
	}

	public function setOptions($options) {
		$this->options = json_encode($options);
	}

	public function getOption($name) {
		$o = $this->getOptions();
		return isset($o[$name]) ? $o[$name] : null;
	}

	public function setOption($name, $value) {
		$o = $this->getOptions();
		$o[$name] = $value;
		$this->setOptions($o);
	}
	
	
	public function getDefault() {
		if($this->defaultModified || $this->isNew()) {
			return $this->default;
		}
		
		$c = Table::getInstance($this->getTableName())->getColumn($this->databaseName);
		
		if(!$c) {
			GO()->debug("Column for custom field ".$this->databaseName." not found in ". $this->getTableName());
			return null;
		}
		
		return $c->default;
	}
	
	public function setDefault($v) {
		$this->default = $v;
		$this->defaultModified = true;
	}
	
	
	public function getUnique() {
		if($this->uniqueModified || $this->isNew()) {
			return $this->unique;
		}
		
		$c = Table::getInstance($this->getTableName())->getColumn($this->databaseName);
		
		if(!$c) {
			GO()->debug("Column for custom field ".$this->databaseName." not found in ". $this->getTableName());
			return null;
		}
		
		return !!$c->unique;
						
	}
	
	public function setUnique($v) {
		$this->unique = $v;
		$this->uniqueModified = true;
	}

	/**
	 * 
	 * @return Base
	 */
	private function getDataType() {
		$dataType = Base::findByName($this->type);
		return (new $dataType($this));
	}

	protected function internalSave() {

		$this->alterColumn();

		return parent::internalSave();
	}

	protected function internalDelete() {

		$this->dropColumn();

		return parent::internalDelete();
	}

	private function getTableName() {
		$fieldSet = FieldSet::findById($this->fieldSetId);
		$entityType = EntityType::findByName($fieldSet->getEntity());
		$entityCls = $entityType->getClassName();
		return $entityCls::customFieldsTableName(); //From customfieldstrait
	}

	private function alterColumn() {
		
		$table = $this->getTableName();
		$fieldSql = $this->getDataType()->getFieldSQL();
		
		$quotedDbName = Utils::quoteColumnName($this->databaseName);
	
		if ($this->isNew()) {
			$sql = "ALTER TABLE `" . $table . "` ADD " . $quotedDbName . " " . $fieldSql . ";";
			GO()->getDbConnection()->query($sql);
			if($this->getUnique()) {
				$sql = "ALTER TABLE `" . $table . "` ADD UNIQUE(". $quotedDbName  . ");";
				GO()->getDbConnection()->query($sql);
			}			
		} else {
			$oldName = $this->isModified('databaseName') ? $this->getOldValue("databaseName") : $this->databaseName;
			$sql = "ALTER TABLE `" . $table . "` CHANGE " . Utils::quoteColumnName($oldName) . " " . $quotedDbName . " " . $fieldSql;
			GO()->getDbConnection()->query($sql);
			
			if($this->getUnique() && !Table::getInstance($table)->getColumn($this->databaseName)->unique) {
				$sql = "ALTER TABLE `" . $table . "` ADD UNIQUE(". $quotedDbName  . ");";
				GO()->getDbConnection()->query($sql);
			} else if(!$this->getUnique() && Table::getInstance($table)->getColumn($this->databaseName)->unique) {
				$sql = "ALTER TABLE `" . $table . "` DROP INDEX " . $quotedDbName;
				GO()->getDbConnection()->query($sql);
			}
		}
		
		Table::getInstance($table)->clearCache();
	}

	private function dropColumn() {
		$table = $this->getTableName();
		$sql = "ALTER TABLE `" . $table . "` DROP " . Utils::quoteColumnName($this->databaseName) ;

		try {
			GO()->getDbConnection()->query($sql);
		} catch (Exception $e) {
			ErrorHandler::logException($e);
		}
		
		Table::getInstance($table)->clearCache();
	}

	public function apiToDb($value, $values) {

		return $this->getDataType()->apiToDb($value, $values);
	}

	public function dbToApi($value, $values) {
		return $this->getDataType()->dbToApi($value, $values);
	}

	public static function filter(Query $query, array $filter) {

		if (!empty($filter['fieldSetId'])) {
			$query->andWhere(['fieldSetId' => $filter['fieldSetId']]);
		}

		return parent::filter($query, $filter);
	}
	
	/**
	 * Find all fields for an entity
	 * 
	 * @param int $enityId
	 * @return Query
	 */
	public static function findByEntity($entityId) {
		return static::find()->where(['fs.entityId' => $entityId])->join('core_customfields_field_set', 'fs', 'fs.id = f.fieldSetId');
	}

}
