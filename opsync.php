<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once('API.php');
use WHMCS\Database\Capsule;

function opsync_config() {
    $configarray = array(
    "name" => "OPSync",
    "description" => "This module will import the extension pricing from OpenProvider",
    "version" => "1.0",
    "author" => "Hoeky.nl",
    "fields" => array(
        "username" => array (
            "FriendlyName" => "OpenProvider username",
            "Type" => "text",
            "Size" => "100",
            "Description" => "Your OpenProvider username"
        ),
        "password" => array (
            "FriendlyName" => "OpenProvider password",
            "Type" => "password",
            "Size" => "100",
            "Description" => "Your OpenProvider password"
        ),
        "margin" => array (
            "FriendlyName" => "Price margin",
            "Type" => "text",
            "Size" => "4",
            "Description" => "Set your pricing margin for extensions. 20 = 20% margin",
            "Default" => "0"
        )
    ));
    return $configarray;
}

function opsync_output($vars) {
    $smarty = new Smarty();
    $smarty->caching = false;
    $smarty->compile_dir = $GLOBALS['templates_compiledir'];

	if(isset($_POST['remove']) || isset($_POST['import']) ){
		
		$toRemove = explode("\n", $_POST['remove']);
		if ( !empty($toRemove) ) {
		  foreach ( $toRemove as $extensionToRemove ) {
			opProcessExtension($vars, preg_replace('{^\.}', '', $extensionToRemove, 1), 0);
		  }
		}
		
		$toImport = explode("\n", $_POST['import']);
		if ( !empty($toImport) ) {
		  foreach ( $toImport as $extensionToImport ) {
			opProcessExtension($vars, preg_replace('{^\.}', '', $extensionToImport, 1), 1);
		  }
		}
	}
	foreach (Capsule::table('tbldomainpricing')->get() as $domain) {
		if ($domain->autoreg == 'openprovider') {
			$extensionlistright[$domain->extension] = $domain->extension;
		}
	}
	$smarty->assign('extensionlistright',$extensionlistright);
	$smarty->display(dirname(__FILE__) . '/files/opsync.tpl');
}

function opProcessExtension($vars, $extensiontoProcess, $action) {

	if($action = 1 && $extensiontoProcess !='') {
		$registerPrice = opGetExtensionPricing($vars, $extensiontoProcess, 'create');
		$transferPrice = opGetExtensionPricing($vars, $extensiontoProcess, 'transfer');
		$renewPrice = opGetExtensionPricing($vars, $extensiontoProcess, 'renew');
        // Get first result in order
		$orderNumber = Capsule::table('tbldomainpricing')->select('order')->orderBy('order', 'desc')->first();
		
		// Update if extension exists in database or create if it doesn't
		if (Capsule::table('tbldomainpricing')->where('extension', '.' . $extensiontoProcess)->first()) {
			echo $extensiontoProcess . " update<br>";
			opUpdateExtension($vars, $extensiontoProcess, $registerPrice, $transferPrice, $renewPrice);
		} else {
			echo $extensiontoProcess . " insert<br>";
			opInsertExtension($vars, $extensiontoProcess, $registerPrice, $transferPrice, $renewPrice, $orderNumber->order+1);
		}
	}
	
	if($action = 0 && $extensiontoProcess !='') {
        if ($extension = Capsule::table('tbldomainpricing')->where('extension', $extensiontoProcess)->where('autoreg', 'openprovider')->first()) {
            Capsule::table('tbldomainpricing')->where('extension', $extension->extension)->where('autoreg', 'openprovider')->delete();
            Capsule::table('tblpricing')->where('relid', $extension->id)->delete();
        }
	}	

}

function opGetExtensionPricing($vars, $extension, $operation) {
	// Create a new API connection
	$api = new OP_API ('https://api.openprovider.eu');

	$request = new OP_Request;
	$request->setCommand('retrievePriceDomainRequest')
		->setAuth(array(
			'username' => $vars['username'], 
			'password' => $vars['password']
		))
		->setArgs(array(
			'domain' => array(
				'name' => 'domain',
				'extension' => $extension
		),
			'operation' => $operation,
		));
	$reply = $api->setDebug(0)->process($request);

	if($reply->getFaultCode() != '0' || $reply->getFaultString()) {
		die($extension . "OP API ERROR");
	}
  
	$price = $reply->getValue()[price][reseller][price];
	
	$price = $price+($price*($vars['margin']/100));

    return (json_decode($price, true));
}

function opInsertExtension($vars, $extension, $firstyear_price, $transferprice, $costprice, $order) {
	
    $relid = Capsule::table('tbldomainpricing')->insertGetId([
        'extension' => '.' . $extension,
        'dnsmanagement' => '1',
        'emailforwarding' => '0',
        'idprotection' => '0',
        'eppcode' => '1',
        'autoreg' => 'openprovider',
        'order' => $order
    ]);

    $types = [
        // Calculate extension pricing with margin (if set)
        'domainregister' => $firstyear_price,
        'domaintransfer' => $transferprice,
        'domainrenew' => $costprice
    ];

    foreach($types as $type => $price){
        $data = array(
            'id' => NULL,
            'type' => $type,
            'currency' => '1',
            'relid' => $relid,
            'msetupfee' => $price,
            'qsetupfee' => '-1.00',
            'ssetupfee' => '-1.00',
            'asetupfee' => '-1.00',
            'bsetupfee' => '-1.00',
            'tsetupfee' => '0.00',
            'monthly' => '-1.00',
            'quarterly' => '-1.00',
            'semiannually' => '-1.00',
            'annually' => '-1.00',
            'biennially' => '-1.00',
            'triennially' => '0.00'
        );
        Capsule::table('tblpricing')->insert($data);
    }
}

function opUpdateExtension($vars, $extension, $firstyear_price, $transferprice, $costprice) {
    // Get relid and update to autoregister with Openprovider
    $relid = Capsule::table('tbldomainpricing')->where('extension', '.' . $extension)->first();
    Capsule::table('tbldomainpricing')->where('extension', '.' . $extension)->update(['autoreg' => 'openprovider']);

    $types = [
        // Calculate extension pricing with margin (if set)
        'domainregister' => $firstyear_price,
        'domaintransfer' => $transferprice,
        'domainrenew' => $costprice
    ];

    foreach($types as $type => $price){
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['msetupfee' => $price]);
    }
}
