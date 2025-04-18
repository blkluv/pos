<?php
/**
 * Open oAuth2 authentication process
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

namespace OpenTHC\POS\Controller\Auth\oAuth2;

use OpenTHC\JWT;

class Open extends \OpenTHC\Controller\Auth\oAuth2
{
	use \OpenTHC\POS\Traits\OpenAuthBox;
	use \OpenTHC\Traits\FindService;
	use \OpenTHC\Traits\FindContact;
	use \OpenTHC\Traits\FindCompany;

	/**
	 *
	 */
	function __invoke($REQ, $RES, $ARG)
	{
		// Clear Session
		session_regenerate_id(true);
		$_SESSION = [];

		$ret_path = $this->_get_return_path();

		if ( ! empty($_GET['box'])) {

			$box = $_GET['box'];

			if (preg_match('/^v2024\/([\w\-]{43})\/([\w\-]+)$/', $box, $m)) {

				$this->dbc = _dbc('auth');
				$act = $this->open_auth_box($m[1], $m[2]);
				$Service = $this->findService($this->dbc, $act->pk);
				$Contact = $this->findContact($this->dbc, $act->contact);
				$Company = $this->findCompany($this->dbc, $act->company);

				$_SESSION['Contact'] = $Contact;
				$_SESSION['Company'] = $Company;
				$_SESSION['License'] = [
					'id' => $act->license
				];

				return $RES->withRedirect('/auth/init');

			}
		}

		// @deprecated
		if ( ! empty($_GET['jwt'])) {

			// $p = $this->getProvider();
			$sso = new \OpenTHC\Service('sso');
			$res = $sso->post('/api/jwt/verify', [ 'form_params' => [ 'token' => $_GET['jwt'] ] ]);
			// switch ($res['code']) {
			// 	case 200:
			// 		// OK
			// 	default:
			// 		return $RES->withJSON(['meta' => [ 'note' => 'Invalid Token [AOO-033]' ]], 400);
			// }

			$dbc = _dbc('auth');

			try {

				$chk = JWT::decode_only($_GET['jwt']);
				$key = $dbc->fetchOne('SELECT hash FROM auth_service WHERE id = :s0', [ ':s0' => $chk->body->iss ]);
				$jwt = JWT::verify($_GET['jwt'], $key);

				$_SESSION['Contact'] = [
					'id' => $jwt->sub,
				];
				if (empty($_SESSION['Contact']['id'])) {
					return $RES->withJSON(['meta' => [ 'note' => 'Invalid Contact [AOO-035]' ]], 400);
				}

				$_SESSION['Company'] = [
					'id' => $jwt->company,
				];
				if (empty($_SESSION['Company']['id'])) {
					return $RES->withJSON(['meta' => [ 'note' => 'Invalid Company [AOO-042]' ]], 400);
				}

				$_SESSION['License'] = [
					'id' => $jwt->license,
				];
				if (empty($_SESSION['License']['id'])) {
					return $RES->withJSON(['meta' => [ 'note' => 'Invalid License [AOO-049]' ]], 400);
				}

				return $RES->withRedirect('/auth/init');

			} catch (\Exception $e) {
				// What?
			}

			return $RES->withRedirect($ret_path);

		}

		$p = $this->getProvider($ret_path);
		$url = $p->getAuthorizationUrl([
			'scope' => 'contact company license pos',
		]);

		$_SESSION['oauth2-state'] = $p->getState();

		return $RES->withRedirect($url);

	}

	/**
	 *
	 */
	function _get_return_path()
	{
		$ret = '/dashboard';
		if (!empty($_GET['r'])) {
			switch ($_GET['r']) {
				case '1':
				case 'r':
					// @todo should validate the referrer
					$ret = $_SERVER['HTTP_REFERER'];
					break;
				default:
					$ret = $_GET['r'];
					break;
			}
		}

		return $ret;

	}

}
