<?php
namespace FreePBX\modules\Callrecording;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
	public function runBackup($id,$transaction){
		$this->addConfigs([
			'rules' => $this->FreePBX->Callrecording->listAll(),
			'modules' => $this->FreePBX->Callrecording->dumpExtensions(),
			'settings' => $this->dumpAdvancedSettings()
		]);
	}
}