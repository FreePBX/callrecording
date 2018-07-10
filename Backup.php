<?php
namespace FreePBX\modules\Callrecording;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
  public function runBackup($id,$transaction){
    $configs = [];
    $configs['rules'] = $this->FreePBX->Callrecording->listAll();
    $configs['modules'] = $this->FreePBX->Callrecording->dumpExtensions();
    $this->addConfigs($configs);
  }
}