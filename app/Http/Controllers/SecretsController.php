<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request; // todo move everything to utils if no http(s) requests are involved?
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use stdClass;

class SecretsController extends Controller
{
    /**
     * @var string
     */
    private $cacheKey;
    private $retryCount;
    private $suspendedKeys;

    public function __construct()
    {
        $this->cacheKey = 'awsSecrets';
        $this->retryCount = 'secretsRetry';
        $this->suspendedKeys = 'suspendedSecrets';
    }

    /**
     * @throws Exception
     */
    public function get($key){
        if ($this->noService()){
            return null;
        }
        $secrets = $this->fetchSecrets();
        return property_exists($secrets, $key)? $secrets->$key : null;
    }

    /**
     * @throws Exception
     */
    public function getAll()
    {
        if ($this->noService()){
            return new stdClass();
        }
//        error_log('Some message here.');
        return $this->fetchSecrets();
    }

    public function clearSecrets(): bool
    {
        if ($this->noService()){
            return false;
        }
        Cache::forget($this->cacheKey);
        return Cache::get($this->cacheKey) == null;
    }

    /**
     * @throws Exception
     */
    public function isLatest($key, $update = true): bool
    {
        if ($this->noService()){
            return false;
        }
        $retryKey = $this->retryCount . $key;
        $retryCount = Cache::get($retryKey);
        $suspendedKeys = Cache::get($this->suspendedKeys);
//        Cache::put($retryKey,  1);
//        dd($retryCount);
//        dd($suspendedKeys) ;
        if ($suspendedKeys === null){
            $suspendedKeys = [];
        }
        switch (true) {
            /** @noinspection PhpDuplicateSwitchCaseBodyInspection */ case !is_numeric($retryCount):
                if (($suspendedKey = array_search($key, $suspendedKeys)) !== false) {
                    unset($suspendedKeys[$suspendedKey]);
                }
                Cache::put($retryKey,  1);
                break;
            case $retryCount <= 10:
                if (($suspendedKey = array_search($key, $suspendedKeys)) !== false) {
                    unset($suspendedKeys[$suspendedKey]);
                }
                Cache::increment($retryKey);
                break;
            case $retryCount > 10:
                array_push($suspendedKeys,$key);
                $suspendedKeys = array_flip($suspendedKeys);
                $suspendedKeys = array_flip($suspendedKeys);
                $suspendedKeys = array_values($suspendedKeys);
                Cache::put($this->suspendedKeys, $suspendedKeys);
                return true;
            default:
                if (($suspendedKey = array_search($key, $suspendedKeys)) !== false) {
                    unset($suspendedKeys[$suspendedKey]);
                }
                Cache::put($retryKey,  1);
                break;
        }
        $suspendedKeys = array_values($suspendedKeys);
        Cache::put($this->suspendedKeys, $suspendedKeys);
        $aws = $this->fetchSecretsFromAWS();
        $cache = Cache::get($this->cacheKey);
        try {
            $cache = Crypt::decryptString($cache);
        } catch (DecryptException $e) {
            throw new Exception("SecretsDecryptionFailureException");
        }
        $result = $aws === $cache;
        if (!$result && $update)
        {
            Cache::put($this->cacheKey,  Crypt::encryptString($aws));
            config(['secrets' => json_decode($aws)]);
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    private function fetchSecrets()
    {
        $secrets = Cache::rememberForever($this->cacheKey, function () {
            return  Crypt::encryptString($this->fetchSecretsFromAWS());
        });
        try {
            $decrypted = Crypt::decryptString($secrets);
        } catch (DecryptException $e) {
            throw new Exception("SecretsDecryptionFailureException");
        }
        return json_decode($decrypted);
    }

    private function fetchSecretsFromAWS()
    {
        // Create a Secrets Manager Client
        $client = new SecretsManagerClient([
            'profile' => 'poc',
            'version' => 'latest',
            'region' => 'us-east-2',
        ]);

        $secretName = 'test/local';

        try {
            $result = $client->getSecretValue([
                'SecretId' => $secretName,
            ]);

        } catch (AwsException $e) {
            $error = $e->getAwsErrorCode();
            if ($error == 'DecryptionFailureException') {
                // Secrets Manager can't decrypt the protected secret text using the provided AWS KMS key.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InternalServiceErrorException') {
                // An error occurred on the server side.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InvalidParameterException') {
                // You provided an invalid value for a parameter.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InvalidRequestException') {
                // You provided a parameter value that is not valid for the current state of the resource.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'ResourceNotFoundException') {
                // We can't find the resource that you asked for.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
        }
        // Decrypts secret using the associated KMS CMK.
        // Depending on whether the secret is a string or binary, one of these fields will be populated.
        if (isset($result['SecretString'])) {
            $secret = $result['SecretString'];
        } else {
            $secret = base64_decode($result['SecretBinary']); // we wont be using this
        }

        //we are assuming that $secret will either contains a json string or null
        return $secret;
    }

    private function noService(): bool
    {
        if (env('APP_KEY') === '' || env('APP_KEY' === null)){
            return true;
        }
        return false;
    }
}
