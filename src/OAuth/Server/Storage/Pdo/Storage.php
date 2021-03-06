<?php
namespace OAuth\Server\Storage\Pdo;

/**
 * @package OAuth_Server
 * @author Warnar Boekkooi
 *
 * The MIT License
 *
 * Copyright (c) 2010 Warnar Boekkooi
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the \"Software\"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
*/
class Storage implements \OAuth\Server\Storage\StorageInterface
{
    /**
     * @var PDO
     */
    protected $db;
	
	/**
     * Constructor.
     *
     * @param PDO|null $pdo An PDO instance.
     * @return void
	 */
    public function __construct(\PDO $db = null)
    {
        $this->setPdo($db);
    }

	public function setPdo(\PDO $db = null) {
        $this->db = $db;
		return $this;
	}

	public function getPdo() {
		if (!($this->db instanceof \PDO)) {
			throw new \RuntimeException('No PDO object has been set.');
		}

        return $this->db;
	}

    /**
     * Get the consumer secret based on the given consumer key.
     *
     * @abstract
     * @param string $consumerKey A client credentials identifier.
     * @return string|null The consumer secret or NULL when the consumer is unknown.
     */
    public function getCustomerSecret($consumerKey)
    {
		$sql = 'SELECT `secret` FROM `oauth_consumer` WHERE `key` = :consumerKey';
		$params = array('consumerKey' => $consumerKey);

		return $this->fetchSingleScalar($sql, $params);
    }

    /**
     * Get the callback uri of the given temporary token.
     *
     * @abstract
     * @param string $temporaryToken A temporary credentials identifier.
     * @return void
     */
    public function getCallbackUri($temporaryToken)
    {
        $sql = 'SELECT `callbackUri` FROM `oauth_consumer_temporary` WHERE `token` = :token';
		$params = array('token' => $temporaryToken);

		return $this->fetchSingleScalar($sql, $params);
    }

    /**
     * Get temporary credentials for the first part of the authentication process
     *
     * @abstract
     * @param string $consumerKey A client credentials identifier.
     * @return void
     */
    public function createTemporaryCredentials($consumerKey, $callbackUri = null)
    {
		// Get the customer id
		$sql = 'SELECT `id` FROM `oauth_consumer` WHERE `key` = :consumerKey';
		$params = array('consumerKey' => $consumerKey);
		$customId = $this->fetchSingleScalar($sql, $params);
		if ($customId === null) {
			return null;
		}

		// Add the token to the system
		$credentials = $this->createCredentials();

		// Insert credentials
        $sql = 'INSERT INTO `oauth_consumer_temporary` (`consumer_id`, `token`, `secret`, `callbackUri`, `created`) VALUES (:consumerId, :token, :secret, :callbackUri, CURRENT_TIMESTAMP)';
        $params = array(
            'consumerId' => $customId,
            'token' => $credentials->getToken(),
            'secret' => $credentials->getSecret(),
            'callbackUri' => $callbackUri
        );

		return $this->execSingleRow($sql, $params) ? $credentials : null;
    }

    /**
     * Get/create a verification code for the given temporary token.
     *
     * @abstract
     * @param string $temporaryToken A temporary credentials identifier.
     * @return string|null
     */
    public function createVerificationCode($temporaryToken, $user)
    {
		$verifierCode = Credentials::generateString();

        $sql = 'UPDATE `oauth_consumer_temporary` SET `verifyCode` = :verifyCode, `user_id` = :user WHERE `oauth_consumer_temporary`.`token` = :token';
		$params = array(
            'token' => $temporaryToken,
            'verifyCode' => $verifierCode,
            'user' => (string)$user
        );

		return $this->execSingleRow($sql, $params) ? $verifierCode : null;
    }

	/**
	 * Get the temporary token secret based on the given token.
	 *
	 * @abstract
	 * @param string $token A temporary token.
	 * @return string|null The temporary token secret secret or NULL when the temporary token is unknown.
	 */
	public function getTemporaryTokenSecret($token) {
        $sql = 'SELECT `secret` FROM `oauth_consumer_temporary` WHERE `token` = :token';
		$params = array('token' => $token);

		return $this->fetchSingleScalar($sql, $params);
	}

	/**
	 * Get the token secret based on the given token.
	 *
	 * @abstract
	 * @param string $token A token.
	 * @return string|null The token secret secret or NULL when the token is unknown.
	 */
	public function getTokenSecret($token, $consumerKey) {
        $sql = 'SELECT `t`.`secret` FROM `oauth_consumer_access_token` AS `t`
        	INNER JOIN `oauth_consumer_access` AS `a` ON `a`.`id` = `t`.`consumer_access_id`
        	INNER JOIN `oauth_consumer` AS `c` ON `c`.`id` = `a`.`consumer_id` AND `c`.`key` = :consumerKey
        	WHERE `t`.`token` = :token';
		$params = array('token' => $token, 'consumerKey' => $consumerKey);

		return $this->fetchSingleScalar($sql, $params);
	}

	public function getAccessInformation($token, $consumerKey) {
        $sql = 'SELECT `a`.`user_id`, `a`.`consumer_id` FROM `oauth_consumer_access` AS `a`
        	INNER JOIN `oauth_consumer` AS `c` ON `c`.`id` = `a`.`consumer_id` AND `c`.`key` = :consumerKey
        	INNER JOIN `oauth_consumer_access_token` AS `t` ON `t`.`token` = :token AND `t`.`consumer_access_id` = `a`.`id`';
		$params = array('token' => $token, 'consumerKey' => $consumerKey);

		$row = $this->fetchSingleRow($sql, $params);
		if (empty($row)) {
			return null;
		}

		$information = new \OAuth\Server\AccessInformation();
		$information->setConsumerId($row['consumer_id']);
		$information->setUserId($row['user_id']);
		return $information;
	}

	/**
	 * Get temporary credentials for the first part of the authentication process
	 *
	 * @abstract
	 * @param string $consumerKey A client credentials identifier.
	 * @return void
	 */
	public function createAccessCredentials($verifierCode, $temporaryToken, $consumerKey) {
		// Get userId and consumerId
        $sql = 'SELECT `t`.`user_id`, `t`.`consumer_id` FROM `oauth_consumer_temporary` AS `t` INNER JOIN `oauth_consumer` AS `c` ON `c`.id = `t`.`consumer_id` AND `c`.`key` = :consumerKey WHERE `t`.`token` = :token AND `t`.`verifyCode` = :verifyCode';
		$params = array(
			'token' => $temporaryToken,
			'verifyCode' => $verifierCode,
			'consumerKey' => $consumerKey
		);
		$row = $this->fetchSingleRow($sql, $params);
		if (empty($row)) {
			return null;
		}

		// Delete temp token
		if (!$this->execSingleRow('DELETE FROM `oauth_token` WHERE `token` = :token ', array('token' => $temporaryToken))) {
			return null;
		}

		// Create/get access granted by user
		$params = array(
            'consumerId' => $row[1],
            'userId' => $row[0]
        );
		$sql = 'SELECT `id` FROM `oauth_consumer_access` WHERE `user_id`= :userId AND `consumer_id` = :consumerId';
		$id = $this->fetchSingleScalar($sql, $params);
		if ($id === null) {
			$insertSql = 'INSERT INTO `oauth_consumer_access` (`user_id`, `consumer_id`) VALUES (:userId, :consumerId)';
			if (!$this->execSingleRow($insertSql, $params)) {
				return null;
			}
			$id = $this->fetchSingleScalar($sql, $params);
		}
		
		// Add the token to the system
		$credentials = $this->createCredentials();

		// Insert credentials
        $sql = 'INSERT INTO `oauth_consumer_access_token` (`consumer_access_id`, `token`, `secret`) VALUES (:consumerAccessId, :token, :secret)';
        $params = array(
            'token' => $credentials->getToken(),
            'secret' => $credentials->getSecret(),
            'consumerAccessId' => $id
        );

		return $this->execSingleRow($sql, $params) ? $credentials : null;
	}

	/**
	 * Validate if the given verification code is correct in combination with the temporary token and the consumer key.
	 *
	 * @abstract
	 * @param string $verifierCode A verification code.
	 * @param string $temporaryToken A temporary credentials identifier.
	 * @param string $consumerKey A client credentials identifier.
	 * @return boolean True if the verification code is valid else FALSE.
	 */
	public function isValidVerifierCode($verifierCode, $temporaryToken, $consumerKey) {
        $sql = 'SELECT `t`.`id` FROM `oauth_consumer_temporary` AS t INNER JOIN `oauth_consumer` AS `c` ON `c`.id = `t`.`consumer_id` AND `c`.`key` = :consumerKey WHERE `t`.`token` = :token AND `t`.`verifyCode` = :verifyCode';
		$params = array(
			'token' => $temporaryToken,
			'verifyCode' => $verifierCode,
			'consumerKey' => $consumerKey
		);

		return $this->fetchSingleScalar($sql, $params) !== null;
	}

	/**
	 * Validate that the given nonce+timestamp have not happened before.
	 * This is used for a two-legged oauth server
	 *
	 * @param string $nonce A Nonce.
	 * @param int $timestamp A timestamp since the Unix Epoch.
	 * @return bool
	 */
	function isValidRequest(\OAuth\Server\Request\RequestInterface $request) {
		$requestParams = $request->getParams();
		ksort($requestParams);
		
        $sql = 'INSERT INTO `oauth_request` (`request`, `nonce`, `timestamp`) VALUES (:request, :nonce, :ts)';
        $params = array(
			'request' => md5($request->getRequestUri() . "\n" . json_encode($requestParams)),
            'nonce' => $request->getParam('oauth_nonce'),
            'ts' => date('Y-m-d H:i:s.u', $request->getParam('oauth_timestamp'))
        );

		return $this->execSingleRow($sql, $params);
	}

	protected function execSingleRow($sql, array $params) {
		$statement = $this->db->prepare($sql);
		$this->db->beginTransaction();
        try {
            if ($statement->execute($params)) {
                if ($statement->rowCount() === 1) {
                    $this->db->commit();
                    return true;
                }
            }
        } catch (\PDOException $e) {
        }
        $this->db->rollBack();
        return false;
	}
	
	protected function fetchSingleScalar($sql, array $params, $default = null)
	{
		$rtn = $this->fetchSingleRow($sql, $params, $default);
		if (is_array($rtn)) {
			return $rtn[0];
		}
		return null;
	}

	protected function fetchSingleRow($sql, array $params, $default = null)
	{
        $statement = $this->db->prepare($sql);

        if (!$statement->execute($params)) {
            return $default;
        }

        $rtn = $default;
        $results = $statement->fetchAll();
        if (count($results) === 1) {
            $rtn = $results[0];
        }
        $statement->closeCursor();

        return $rtn;
	}

	protected function createCredentials() {
		do {
			// Generate a set of credentials
			$credentials = Credentials::generateCredentials();

			// Add the token to the token table
			// Insert credentials
			$sql = 'INSERT INTO `oauth_token` (`token`) VALUES (:token)';
			$params = array(
				'token' => $credentials->getToken()
			);
		} while (!$this->execSingleRow($sql, $params));
		
		return $credentials;
	}
}
