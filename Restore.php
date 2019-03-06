<?php
namespace FreePBX\modules\Callrecording;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
	public function runRestore(){
		$configs = $this->getConfigs();
		$rules = is_array($configs['rules'])?$configs['rules']:[];
		$modules = is_array($configs['modules'])?$configs['modules']:[];
		foreach ($rules as $rule) {
			$this->FreePBX->Callrecording->upsert($rule['callrecording_id'], $rule['description'], $rule['callrecording_mode'], $rule['dest']);
		}
		foreach ($modules as $module) {
			$this->FreePBX->Callrecording->insertExtensionData($module['extension'], $module['cidnum'], $module['callrecording'], $module['display']);
		}
		$this->importAdvancedSettings($configs['settings']);
	}

	public function processLegacy($pdo, $data, $tables, $unknownTables){
		$this->restoreLegacyDatabase($pdo);
		$this->restoreLegacyAdvancedSettings($pdo);
	}
}