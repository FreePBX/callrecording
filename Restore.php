<?php
namespace FreePBX\modules\Callrecording;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
	public function runRestore($jobid){
		$configs = $this->getConfigs();
		$rules = is_array($configs['rules'])?$configs['rules']:[];
		$modules = is_array($configs['modules'])?$configs['modules']:[];
		foreach ($rules as $rule) {
			$this->FreePBX->Callrecording->upsert($rule['callrecording_id'], $rule['description'], $rule['callrecording_mode'], $rule['dest']);
		}
		foreach ($modules as $module) {
			$this->FreePBX->Callrecording->insertExtensionData($module['extension'], $module['cidnum'], $module['callrecording'], $module['display']);
		}
	}

	public function processLegacy($pdo, $data, $tables, $unknownTables){
		$advanced = ['CALLREC_BEEP_PERIOD', 'CALL_REC_OPTION'];
		foreach ($advanced as $key) {
			if(isset($data['settings'][$key])){
				$this->FreePBX->Config->update($key, $data['settings'][$key]);
			}
		}

		$tables = ['callrecording', 'callrecording_module'];
		foreach($tables as $table) {
			$sth = $pdo->query("SELECT * FROM $table",\PDO::FETCH_ASSOC);
			$res = $sth->fetchAll();
			$this->addDataToTableFromArray($table, $res);
		}
	}
}