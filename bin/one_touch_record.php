#!/usr/bin/php -q
<?php 

// Copyright (C) 2012 HEHE Enterprises, LLC d.b.a. i9 Technologies
//
// This file is part of FreePBX.
//
// FreePBX is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 2 of the License, or
// (at your option) any later version.
//
// FreePBX is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with FreePBX.  If not, see <http://www.gnu.org/licenses/>.

//Bootstrap FreePBX
$bootstrap_settings['freepbx_auth'] = false;
if(!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

echo("Starting...\n");
$channel = $argv[1];

echo("Channel: {$channel}\n");

//Attempt to determin the extension
echo("Gathering variables\n");
$pickupExten = getVariable($channel, "PICKUP_EXTEN");
$callFileName = getVariable($channel, "CALLFILENAME");
$thisExtension = getVariable($channel, "THISEXTEN");
$realCallerIdNum = getVariable($channel, "REALCALLERIDNUM");
$fromExten = getVariable($channel, "FROMEXTEN");
$callFileNameParts = explode("-", $callFileName);
$callFileNameExten = $callFileNameParts[1];
$callFileNameType = $callFileNameParts[0];

echo("PICKUP_EXTEN: {$pickupExten}\n");
echo("CALLFILENAME: {$callFileName}\n");
echo("THISEXTEN: {$thisExtension}\n");
echo("REALCALLERIDNUM: {$realCallerIdNum}\n");
echo("FROMEXTEN: {$fromExten}\n");
echo("callFileNameExten: {$callFileNameExten}\n");
echo("callFileNameType: {$callFileNameType}\n");

echo("Checking pickup extension\n");
if($pickupExten != "") {
	echo("Setting THISEXTEN to {$callFileNameExten}\n");
	$astman->SetVar($channel, "THISEXTEN", $callFileNameExten);
	$thisExtension = $callFileNameExten;
}

echo("Checking this extension\n");
if($thisExtension == "") {
	$thisExtension = ($realCallerIdNum == "" ? $callFileNameExten : $fromExten);
	echo("Setting THISEXTEN to {$thisExtension}\n");
	$astman->SetVar($channel, "THISEXTEN", $thisExtension);
}

//Check on demand setting for the extension
echo("Checking on demand setting\n");
$extenRecordingOnDemand = $astman->database_get("AMPUSER/{$thisExtension}/recording", "ondemand");
echo("AMPUSER/{$thisExtension}/recording/ondemand: {$extenRecordingOnDemand}\n");
if($callFileNameType == "exten" && $extenRecordingOnDemand != "enabled") {
	echo("On demand setting off exiting\n");
	exit(0);
}

//Grab the bridge peer
$bridgePeer = getVariable($channel, "BRIDGEPEER");
echo("BRIDGEPEER: {$bridgePeer}\n");

//If channel is already being recoreded if so stop the recording
echo("Checking if channel is already recording\n");
$masterChannelOneTouchRec = getVariable($bridgePeer, "ONETOUCH_REC");
echo("MASTER_CHANNEL(ONETOUCH_REC): {$masterChannelOneTouchRec}\n");
if($masterChannelOneTouchRec == "RECORDING") {
	echo("Stop recording channel\n");
	$astman->stopmixmonitor($channel, rand());
	$astman->SetVar($bridgePeer, "ONETOUCH_REC", "PAUSED");
	$astman->SetVar($bridgePeer, "REC_STATUS", "PAUSED");
	if($thisExtension == "") {
		$dialPeerNumber = getVariable($channel, "DIALEDPEERNUMBER");
		echo("DIALEDPEERNUMBER: {$dialPeerNumber}\n");
		$thisExtension = ($realCallerIdNum == "" ? $dialPeerNumber : $fromExten);
		$astman->SetVar($channel, "THISEXTEN", $thisExtension);
	}
	exit(0);
}

//If the recording poicy is never exit
echo("Checking recording polcy\n");
$masterChannelRecPolicyMode = getVariable($bridgePeer, "REC_POLICY_MODE");
echo("MASTER_CHANNEL(REC_POLICY_MODE): {$masterChannelRecPolicyMode}\n");
if($masterChannelRecPolicyMode == "never") {
	echo("Recording polcy is never exiting\n");
	exit(0);
}

//Check one touch recording setting
echo("Checking one touch recording\n");
$masterChannelRecStatus = getVariable($bridgePeer, "REC_STATUS");
echo("MASTER_CHANNEL(REC_STATUS): {$masterChannelRecStatus}\n");
if($masterChannelOneTouchRec == "" && $masterChannelRecStatus == "RECORDING") {
	echo("One touch recording is blank exiting\n");
	exit(0);
}

//Start recording the channel
echo("Recording Channel\n");
$mixMonDir = getVariable($channel, "MIXMON_DIR");
$year = getVariable($channel, "YEAR");
$month = getVariable($channel, "MONTH");
$day = getVariable($channel, "DAY");
$mixMonFormat = getVariable($channel, "MIXMON_FORMAT");
$mixMonPost = getVariable($channel, "MIXMON_POST");
echo("MIXMON_DIR: {$mixMonDir}\n");
echo("YEAR: {$year}\n");
echo("MONTH: {$month}\n");
echo("DAY: {$day}\n");
echo("MIXMON_FORMAT: {$mixMonFormat}\n");
echo("MIXMON_POST: {$mixMonPost}\n");

$astman->SetVar($bridgePeer, "ONETOUCH_REC", "RECORDING");
$astman->SetVar($bridgePeer, "REC_STATUS", "RECORDING");
$astman->SetVar($channel, "AUDIOHOOK_INHERIT(MixMonitor)", "yes");
$astman->mixmonitor($channel, "{$mixMonDir}{$year}/{$month}/{$day}/{$callFileName}.{$mixMonFormat}", "a", $mixMonPost, rand());
	
//Set the monitor format and file name for the cdr entry
echo("Setting CDR info\n");
$monFmt = ($mixMonDir != "" ? $mixMonDir : "wav");
$astman->SetVar($channel, "MON_FMT", $monFmt);
$astman->SetVar($bridgePeer, "CDR(recordingfile)", "{$callFileName}.{$monFmt}");
$astman->SetVar($channel, "CDR(recordingfile)", "{$callFileName}.{$monFmt}");

// The following 2 lines are to deal with a bug in Asterisk 1.8 not setting the CDR rec file after hangup
$astman->SetVar($bridgePeer, "ONETOUCH_RECFILE", "{$callFileName}.{$monFmt}");
$astman->SetVar($channel, "ONETOUCH_RECFILE", "{$callFileName}.{$monFmt}");

//Get variable function
function getVariable($channel, $varName) {
	global $astman;

	$results = $astman->GetVar($channel, $varName, rand());

	if($results["Response"] != "Success"){
		echo "Failed to get var {$varName} exiting";
		exit(1);
	}

	return $results["Value"];
}

?>

