<?php
/**
 * POS Checkout Commit
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

namespace OpenTHC\POS\Controller\POS\Checkout;

use Edoceo\Radix\Session;
use Edoceo\Radix\ULID;

class Commit extends \OpenTHC\Controller\Base
{
	/**
	 *
	 */
	function __invoke($REQ, $RES, $ARG)
	{
		$_POST['cash_incoming'] = floatval($_POST['cash_incoming']);
		$_POST['cash_outgoing'] = floatval($_POST['cash_outgoing']);

		$dbc = $this->_container->DB;

		$Company = new \OpenTHC\Company($dbc, $_SESSION['Company']);
		$License = new \OpenTHC\License($dbc, $_SESSION['License']['id']);

		$tax_incl = $Company->getOption(sprintf('/%s/b2b-item-price-adjust/tax-included', $License['id']));
		$tax_list = [];
		$tax0_pct = $Company->getOption(sprintf('/%s/b2b-item-price-adjust/010PENTHC00BIPA0SST03Q484J', $License['id'])); // State
		$tax1_pct = $Company->getOption(sprintf('/%s/b2b-item-price-adjust/010PENTHC00BIPA0C0T620S2M2', $License['id'])); // County
		$tax2_pct = $Company->getOption(sprintf('/%s/b2b-item-price-adjust/010PENTHC0PDTNJ1WNK5H9S6T3', $License['id'])); // City
		$tax3_pct = $Company->getOption(sprintf('/%s/b2b-item-price-adjust/010PENTHC0PDTSV845B6FEEGCF', $License['id'])); // Regional
		$tax4_pct = $Company->getOption(sprintf('/%s/b2b-item-price-adjust/010PENTHC00BIPA0ET0FNBCKMH', $License['id'])); // Excise

		try {

			$dbc->query('BEGIN');

			$b2c_item_count = 0;
			$sum_item_price = 0;

			$Sale = new \OpenTHC\POS\B2C\Sale($dbc);
			$Sale['id'] = ULID::create();
			$Sale['license_id'] = $License['id'];
			$Sale['contact_id'] = $_SESSION['Contact']['id'];
			$Sale['guid'] = $Sale['id'];
			$Sale['meta'] = json_encode([
				'_SESSION/Checkout' => $_SESSION['Checkout'],
				'_POST' => $_POST,
			]);
			$Sale->save('B2C/Sale/Create');

			$key_list = array_keys($_POST);
			foreach ($key_list as $key) {
				// @todo Need to Handle "Special" line items
				// Like, Loyalty or Tax or ??? -- Could those be "system" class Inventory to add to a ticket?
				// And Don't Decrement Them?
				if (preg_match('/^qty\-(\w+)/', $key, $m)) {

					$qty = floatval($_POST[$key]);
					if ($qty <= 0) {
						continue;
					}

					$IL = new \OpenTHC\POS\Lot($dbc, $m[1]);
					if (empty($IL['id'])) {
						throw new \Exception('Inventory Lost on Sale [PCC-055]');
					}

					$P = new \OpenTHC\POS\Product($dbc, $IL['product_id']);
					switch ($P['package_type']) {
						case 'pack':
						case 'each':
							$b2c_item_count += $qty;
							$uom = 'ea';
							break;
						case 'bulk':
							$b2c_item_count++;
							$uom = new \OpenTHC\UOM($P['package_unit_uom']);
							$uom = $uom->getStub();
							break;
					}

					$SI = new \OpenTHC\POS\B2C\Sale\Item($dbc);
					$SI['id'] = ULID::create();
					$SI['b2c_sale_id'] = $Sale['id'];
					$SI['inventory_id'] = $IL['id'];
					$SI['unit_count'] = $qty;
					$SI['unit_price'] = floatval($IL['sell']);
					$SI['uom'] = $uom;
					$SI->save('B2C/Sale/Item/Create');

					$IL->decrement($qty);

					// Foreach tax_list as $tax
					//  insert into b2b_sale_item_tax (b2b_sale_id, b2b_sale_item_id, tax_plan_id, tax_amount)

					$sum_item_price += ($SI['unit_price'] * $SI['unit_count']);

				}
			}

			// Excise Taxes
			// $opt_help->get('/%s/)
			// $tax_excise_rate = $tax4_pct;
			// if ($tax_excise_rate > 1) {
			// 	$tax_excise_rate = $tax_excise_rate / 100;
			// }
			// if ($tax_excise_rate > 0) {
			// 	$SI = new \OpenTHC\POS\B2C\Sale\Item($dbc);
			// 	$SI['id'] = ULID::create();
			// 	$SI['b2c_sale_id'] = $Sale['id'];
			// 	// $SI['inventory_id'] = '';
			// 	$SI['guid'] = '-';
			// 	$SI['unit_count'] = 1;
			// 	$SI['unit_price'] = ($sum_item_price * $tax_excise_rate);
			// 	$SI->setFlag(\OpenTHC\POS\B2C\Sale\Item::FLAG_TAX_EXCISE);
			// 	// $SI->save();
			// }

			// Retail/Sales Taxes
			// $License->opt('tax-retail-rate') ??
			// $arg = [
			// 	':k' => sprintf('/%s/tax-retail-rate', $_SESSION['License']['id']),
			// ];
			// $tax_retail_rate = $dbc->fetchOne('SELECT val FROM base_option WHERE key = :k', $arg);
			// $tax_retail_rate = floatval($tax_retail_rate);
			// if ($tax_retail_rate > 1) {
			// 	$tax_retail_rate = $tax_retail_rate / 100;
			// }
			// if ($tax_retail_rate > 0) {
			// 	$SI = new \OpenTHC\POS\B2C\Sale\Item($dbc);
			// 	$SI['id'] = ULID::create();
			// 	$SI['b2c_sale_id'] = $Sale['id'];
			// 	$SI['inventory_id'] = -1;
			// 	$SI['guid'] = '-';
			// 	$SI['unit_count'] = 1;
			// 	$SI['unit_price'] = ($sum_item_price * $tax_excise_rate);
			// 	$SI->setFlag(\OpenTHC\POS\B2C\Sale\Item::FLAG_TAX_RETAIL);
			// 	// $SI->save();
			// }

			$Sale['item_count'] = $b2c_item_count;
			$Sale['full_price'] = $sum_item_price + $tax0 + $tax1;
			$Sale->save('B2C/Sale/Commit');

		} catch (\Exception $e) {
			_exit_html_fail(sprintf('<h1>Failed to Execute the Sale [PCC-123]</h1><pre>%s</pre>', __h($e->getMessage())), 500);
		}

		$Sale = $this->sendToCRE($Sale);
		$Sale->save('B2C/Sale/Update from CRE');

		$dbc->query('COMMIT');

		Session::flash('info', sprintf('Sale Confirmed, Transaction #%s', $Sale['guid']));

		return $RES->withRedirect('/pos/checkout/receipt?s=' . $Sale['id']);

	}

	/**
	 * Send the Sale to the CRE
	 */
	function sendToCRE($Sale)
	{
		switch ($_SESSION['cre']['engine']) {
			case 'biotrack':
				$Sale = $this->send_to_biotrack($Sale);
				break;
			case 'metrc':
				$Sale = $this->send_to_metrc($Sale);
				break;
		}

		return $Sale;
	}

	/**
	 * Execute Sale in BioTrack
	 */
	function send_to_biotrack($b2c_sale)
	{
		$cre = \OpenTHC\CRE::factory($_SESSION['cre']);
		$cre->setLicense($_SESSION['License']);

		switch ($_SESSION['cre']['id']) {
		case 'usa/nm':
			return $this->send_to_biotrack_v2022($cre, $b2c_sale);
		default:
			return $this->send_to_biotrack_v2014($cre, $b2c_sale);
		}
	}

	// Sale Dispense v1
	function send_to_biotrack_v2014($cre, $b2c_sale)
	{
		$dbc = $this->_container->DB;
		$rdb = $this->_container->Redis;

		$b2c_item_list = $b2c_sale->getItems();

		// $res = $rbe->card_lookup($_POST['mmj-mp'], $_POST['mmj-cg']);

		$b2c_term = '';
		$b2c_time = new \DateTime($b2c_sale['created_at'], new \DateTimezone('America/Denver'));

		$S['json'] = json_decode($S['json'], true);

		$inv_list = array();
		foreach ($S['json'] as $k => $v) {

			if (preg_match('/^item\-(\d+)$/', $k, $m)) {

				$I = new Inventory($m[1]);
				$s = $S['json'][sprintf('size-%d', $I['id'])];

				if ($I->isRegulated()) {
					$inv_list[] = array(
						'barcodeid' => $I['guid'],
						'quantity' => intval($s),
						'price' => sprintf('%0.2f', $I['sell']),
					);
				}
			}
		}

		if (count($inv_list)) {
			$res = $rbe->sale_dispense($inv_list, strtotime($S['dts']));
			switch ($res['success']) {
			case 0:
				// Tri
				Session::flash('fail', $rbe->formatError($res));
				Radix::redirect('/pos/sale?id=' . $S['id']);
				break;
			case 1:
				Session::flash('info', "Sale {$S['id']} Assigned Transaction {$S['tid']}");
				//syslog(LOG_NOTICE, "Sale {$S['id']} Assigned Transaction {$S['tid']}");
				$S->save();
				//Task::done($task);
				break;
			}
		} else {
			// UnRegulated Sale?
			// ??
		}

		return $b2c_sale;

	}

	function send_to_biotrack_v2022($cre, $b2c_sale)
	{
		// New Stuff Here
		$dbc = $this->_container->DB;
		$rdb = $this->_container->Redis;

		$b2c_item_list = $b2c_sale->getItems();

		$b2c_time = new \DateTime($b2c_sale['created_at'], new \DateTimezone('America/Denver'));

		// Sale Dispense v3
		// https://documenter.getpostman.com/view/15944043/UVktqDR2#bee52c63-f4bf-46ce-a6d2-34099afdb09b
		/*
		Client error: `
		POST https://v3.api.nm.trace.biotrackthc.net/v1/dispense` resulted in a `400 Bad Request` response:
		 {"Error":"Error reading JSON body:
		 parsing time \"\"2024-09-28T16:20:00-0600\"\" as \"\"2006-01-02T15:04:05Z07:00\"\":
		 c (truncated...
		*/
		// "Error": "Error reading JSON body: parsing time \"\"2024-09-28T16:20:00-0600\"\" as \"\"2006-01-02T15:04:05Z07:00\"\": cannot parse \"-0600\"\" as \"Z07:00\""
		// It's using GOLANG as the Parser, so properly put all things in floatval
		$req = [];
		$req['LocationLicense'] = $_SESSION['License']['code'];
		$req['Type'] = 'RECREATIONAL';
		// https://www.php.net/manual/en/class.datetimeinterface.php#datetimeinterface.constants.iso8601
		// PHP ISO8601 is NOT CORRECT, so use ATOM
		$req['Datetime'] = $b2c_time->format(\DateTimeInterface::ATOM);
		$req['RequestID'] = $b2c_sale['id'];
		$req['ExternalID'] = $b2c_sale['id'];
		// PatientCardKey
		// 'TerminalID' => $b2c_term
		$req['Items'] = [];
		foreach ($b2c_item_list as $b2c_item) {
			// $I = new Lot($b2c_item['inventory_id']);
			$Inv = new \OpenTHC\POS\Lot($dbc, $b2c_item['inventory_id']);
			$req['Items'][] = [
				'Barcode' => $Inv['guid'],
				'Quantity' => floatval($b2c_item['unit_count']),
				'Price' => floatval(sprintf('%0.2f', $b2c_item['unit_price'])),
				'Tax' => [
					'Excise' => 0,
					'Other' => floatval($b2c_item['unit_price'] * $b2c_item['unit_count'] * 0.12),
				]
			];
		}

		// Authenticate and then Checkout

		// Needs a good CRE-Adapter or BONG to work
		$ghc = new \GuzzleHttp\Client([
			// 'base_uri' => 'https://v3.api.nm.trace.biotrackthc.net/',
			'base_uri' => 'https://bunk.openthc.dev/biotrack/v2022/',
			'http_errors' => false,
			// 'cookie'
		]);

		$sid = $rdb->get('/cre/biotrack2023/sid');
		if (empty($sid)) {

			$res = $ghc->post('v1/login', [ 'json' => [
				'UBI' => $_SESSION['Company']['cre_meta']['company'],
				'Username' => $_SESSION['Company']['cre_meta']['username'],
				'Password' => $_SESSION['Company']['cre_meta']['password'],
			]]);

			$res = json_decode($res->getBody()->getContents());
			$sid = $res->Session;

			$rdb->set('/cre/biotrack2023/sid', $sid, [ 'ttl' => 3600 ]);
		}

		$res = $ghc->post('v1/dispense', [
			'json' => $req,
			'headers' => [
				'Authorization' => sprintf('Bearer %s', $sid)
			]
		]);
		$res = $res->getBody()->getContents();
		$res = json_decode($res);
		$b2c_sale['guid'] = $res->TransactionID;
		$b2c_sale['guid'] = sprintf('tid:%s', $res->TransactionID);

		return $b2c_sale;

	}

	/**
	 * Execute Sale in Metrc
	 */
	function send_to_metrc($Sale)
	{
		$dbc = $this->_container->DB;

		$cre = \OpenTHC\CRE::factory($_SESSION['cre']);
		$cre->setLicense($_SESSION['License']);

		$obj = [];
		$obj['SalesDateTime'] = date(\DateTime::ISO8601);

		// 'Consumer', 'Caregiver'; 'ExternalPatient', 'Patient'
		switch ($_SESSION['Checkout']['Contact']['id']) {
			case '018NY6XC00C0NTACT000WALK1N':
				$obj['SalesCustomerType'] = 'Consumer';
				break;
			default:
				$obj['SalesCustomerType'] = 'Patient';
				$obj['PatientLicenseNumber'] = $_SESSION['Checkout']['Contact']['guid'];
				break;
		}
		switch ($_SESSION['Checkout']['Contact']['type']) {
			case '018NY6XC00C0NTACTTYPE000AC':
				$obj['SalesCustomerType'] = 'Consumer';
				break;
			case '018NY6XC00C0NTACTTYPE000PA': // Well Known ULID
				$obj['SalesCustomerType'] = 'Patient';
				$obj['PatientLicenseNumber'] = $_SESSION['Checkout']['Contact']['guid'];
				break;
		}

		// @todo Fix assumptions about Customer, add Patient/Caregiver UX
		// $obj['PatientLicenseNumber'] = '12-345-678-DD'; //  $Sale['contact_list']['']; '000001';
		// $obj['CaregiverLicenseNumber'] = 'CLN-DEF456'; // $Sale['contact_list']['']; '000001';
		// $obj['IdentificationMethod'] = 'ID';
		// $obj['PatientRegistrationLocationId'] = '';

		$obj['Transactions'] = [];

		$b2c_item_list = $Sale->getItems();
		foreach ($b2c_item_list as $b2c_item) {
			$lot = new \OpenTHC\POS\Lot($dbc, $b2c_item['inventory_id']);
			$uom = new \OpenTHC\UOM($b2c_item['uom']);
			$uom = $uom->getName();
			$obj['Transactions'][] = [
				// 'CityTax' => null,
				// 'CountyTax' => null,
				// 'DiscountAmount' => null,
				// 'ExciseTax' => null,
				'InvoiceNumber' => $b2c_item['id'],
				// 'MunicipalTax' => null,
				'PackageLabel' => $lot['guid'],
				// 'Price' => $b2c_item['unit_price'],
				'Quantity' => $b2c_item['unit_count'],
				// 'SalesTax' => null,
				// 'SubTotal' => $b2c_item['unit_price'],
				'TotalAmount' => ($b2c_item['unit_price'] * $b2c_item['unit_count']),
				'UnitOfMeasure' => $uom,
				// 'UnitThcContent' => null,
				// 'UnitThcContentUnitOfMeasure' => null,
				// 'UnitThcPercent' => null,
				// 'UnitWeight' => null,
				// 'UnitWeightUnitOfMeasure' => null,
			];
		}

		$api = $cre->b2c();
		$res = $api->create($obj);

		$m = $Sale->getMeta();
		$m['@cre']['result'] = $res;
		$Sale['meta'] = json_encode($m);
		$Sale['stat'] = $res['code'];

		switch ($res['code']) {
			case 200:
				// Great
				break;
			default:
				Session::flash('warn', $cre->formatError($res));
				break;
		}
		// if (200 == $res['code']) {
			// This is not finding the transaction
			// $cre->setTimeAlpha(date(\DateTime::ISO8601, $_SERVER['REQUEST_TIME'] - 60));
			// $cre->setTimeOmega(date(\DateTime::ISO8601, $_SERVER['REQUEST_TIME'] + 60));
			// $res = $api->search('active');
			// foreach ($res['data'] as $chk_b2c) {
			// 	$objB = $api->single($chk_b2c['Id']);
			// }
		// }

		return $Sale;

	}

}
