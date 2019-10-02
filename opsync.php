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
            "Description" => "Set your pricing margin for extensions. 20 = 20% margin. Excl. VAT",
            "Default" => "0"
        ),
        "vat" => array (
            "FriendlyName" => "VAT in %",
            "Type" => "text",
            "Size" => "4",
            "Description" => "Set your VAT. E.g. 19 for 19% VAT",
            "Default" => "0"
        ),
        "beautifier" => array (
            "FriendlyName" => "Price beautifier",
            "Type" => "yesno",
            "Description" => "Round price up to .49 or .99",
            "Default" => "no"
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
	
	$price = $price+($price*($vars['margin']/100))+($price*($vars['vat']/100));
	
	if($vars['beautifier'] == "on"){
		if(substr($price,-2) < "49" && $price != "0"){
			$price = ceil($price)-0.51;
		} elseif($price != "0") {
			$price = ceil($price)-0.01;
		}
	}
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
	
		if($vars['beautifier'] == "on"){
			// 1 year
			$msetupfee = $price;
			
			// 2 years
			if(substr($price*2,-2) < "49" && $price != "0"){
				$qsetupfee = ceil($price*2)-0.51;
			} elseif($price != "0") {
				$qsetupfee = ceil($price*2)-0.01;
			} else {
				$qsetupfee = "0";
			}
			
			// 3 years
			if(substr($price*3,-2) < "49" && $price != "0"){
				$ssetupfee = ceil($price*3)-0.51;
			} elseif($price != "0") {
				$ssetupfee = ceil($price*3)-0.01;
			} else {
				$ssetupfee = "0";
			}

			// 4 years
			if(substr($price*4,-2) < "49" && $price != "0"){
				$asetupfee = ceil($price*4)-0.51;
			} elseif($price != "0") {
				$asetupfee = ceil($price*4)-0.01;
			} else {
				$asetupfee = "0";
			}

			// 5 years
			if(substr($price*5,-2) < "49" && $price != "0"){
				$bsetupfee = ceil($price*5)-0.51;
			} elseif($price != "0") {
				$bsetupfee = ceil($price*5)-0.01;
			} else {
				$bsetupfee = "0";
			}
			
			// 6 years
			if(substr($price*6,-2) < "49" && $price != "0"){
				$monthly = ceil($price*6)-0.51;
			} elseif($price != "0") {
				$monthly = ceil($price*6)-0.01;
			} else {
				$monthly = "0";
			}
			
			// 7 years
			if(substr($price*7,-2) < "49" && $price != "0"){
				$quarterly = ceil($price*7)-0.51;
			} elseif($price != "0") {
				$quarterly = ceil($price*7)-0.01;
			} else {
				$quarterly = "0";
			}
			
			// 8 years
			if(substr($price*8,-2) < "49" && $price != "0"){
				$semiannually = ceil($price*8)-0.51;
			} elseif($price != "0") {
				$semiannually = ceil($price*8)-0.01;
			} else {
				$semiannually = "0";
			}

			// 9 years
			if(substr($price*9,-2) < "49" && $price != "0"){
				$annually = ceil($price*9)-0.51;
			} elseif($price != "0") {
				$annually = ceil($price*9)-0.01;
			} else {
				$annually = "0";
			}
			
			// 10 years
			if(substr($price*10,-2) < "49" && $price != "0"){
				$biennially = ceil($price*10)-0.51;
			} elseif($price != "0") {
				$biennially = ceil($price*10)-0.01;
			} else {
				$biennially = "0";
			}
		} else {
			$msetupfee = $price;
			$qsetupfee = $price*2;
			$ssetupfee = $price*3;
			$asetupfee = $price*4;
			$bsetupfee = $price*5;
			$monthly = $price*6;
			$quarterly = $price*7;
			$semiannually = $price*8;
			$annually = $price*9;
			$biennially = $price*10;
		}

    foreach($types as $type => $price){
        $data = array(
            'id' => NULL,
            'type' => $type,
            'currency' => '1',
            'relid' => $relid,
            'msetupfee' => $msetupfee,
            'qsetupfee' => $qsetupfee,
            'ssetupfee' => $ssetupfee,
            'asetupfee' => $asetupfee,
            'bsetupfee' => $bsetupfee,
            'tsetupfee' => '0.00',
            'monthly' => $monthly,
            'quarterly' => $quarterly,
            'semiannually' => $semiannually,
            'annually' => $annually,
            'biennially' => $biennially,
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
		
		if($vars['beautifier'] == "on"){
			// 1 year
			$msetupfee = $price;
			
			// 2 years
			if(substr($price*2,-2) < "49" && $price != "0"){
				$qsetupfee = ceil($price*2)-0.51;
			} elseif($price != "0") {
				$qsetupfee = ceil($price*2)-0.01;
			} else {
				$qsetupfee = "0";
			}
			
			// 3 years
			if(substr($price*3,-2) < "49" && $price != "0"){
				$ssetupfee = ceil($price*3)-0.51;
			} elseif($price != "0") {
				$ssetupfee = ceil($price*3)-0.01;
			} else {
				$ssetupfee = "0";
			}

			// 4 years
			if(substr($price*4,-2) < "49" && $price != "0"){
				$asetupfee = ceil($price*4)-0.51;
			} elseif($price != "0") {
				$asetupfee = ceil($price*4)-0.01;
			} else {
				$asetupfee = "0";
			}

			// 5 years
			if(substr($price*5,-2) < "49" && $price != "0"){
				$bsetupfee = ceil($price*5)-0.51;
			} elseif($price != "0") {
				$bsetupfee = ceil($price*5)-0.01;
			} else {
				$bsetupfee = "0";
			}
			
			// 6 years
			if(substr($price*6,-2) < "49" && $price != "0"){
				$monthly = ceil($price*6)-0.51;
			} elseif($price != "0") {
				$monthly = ceil($price*6)-0.01;
			} else {
				$monthly = "0";
			}
			
			// 7 years
			if(substr($price*7,-2) < "49" && $price != "0"){
				$quarterly = ceil($price*7)-0.51;
			} elseif($price != "0") {
				$quarterly = ceil($price*7)-0.01;
			} else {
				$quarterly = "0";
			}
			
			// 8 years
			if(substr($price*8,-2) < "49" && $price != "0"){
				$semiannually = ceil($price*8)-0.51;
			} elseif($price != "0") {
				$semiannually = ceil($price*8)-0.01;
			} else {
				$semiannually = "0";
			}

			// 9 years
			if(substr($price*9,-2) < "49" && $price != "0"){
				$annually = ceil($price*9)-0.51;
			} elseif($price != "0") {
				$annually = ceil($price*9)-0.01;
			} else {
				$annually = "0";
			}
			
			// 10 years
			if(substr($price*10,-2) < "49" && $price != "0"){
				$biennially = ceil($price*10)-0.51;
			} elseif($price != "0") {
				$biennially = ceil($price*10)-0.01;
			} else {
				$biennially = "0";
			}
		} else {
			$msetupfee = $price;
			$qsetupfee = $price*2;
			$ssetupfee = $price*3;
			$asetupfee = $price*4;
			$bsetupfee = $price*5;
			$monthly = $price*6;
			$quarterly = $price*7;
			$semiannually = $price*8;
			$annually = $price*9;
			$biennially = $price*10;
		}
	
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['msetupfee' => $msetupfee]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['qsetupfee' => $qsetupfee]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['ssetupfee' => $ssetupfee]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['asetupfee' => $asetupfee]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['bsetupfee' => $bsetupfee]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['monthly' => $monthly]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['quarterly' => $quarterly]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['semiannually' => $semiannually]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['annually' => $annually]);
        Capsule::table('tblpricing')->where('relid', $relid->id)->where('type', $type)->update(['biennially' => $biennially]);
    }
}
