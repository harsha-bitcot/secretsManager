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
    private $retryCount; //todo delete all ref
    private $suspendedKeys; //todo delete all ref

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
        return property_exists($secrets, $key) ? $secrets->$key->value : null;
    }

    /**
     * @throws Exception
     */
    public function getAll()
    {
        if ($this->noService()){
            return new stdClass();
        }
        $secrets = $this->fetchSecrets();
        foreach ($secrets as $key=>$value){
            $secrets->$key = $value->value;
        }
        return $secrets;
    }

    public function markWorking($key): bool
    {
        $secrets = Cache::get($this->cacheKey);
        if ($secrets->$key->retryCount != 0){
            $secrets->$key->retryCount = 0;
            $secrets->$key->status = 'active';
        }
        Cache::put($this->cacheKey, $secrets);
        return true;
    }

    public function clearSecrets(): bool
    {
        if ($this->noService()){
            return false;
        }
        Cache::forget($this->cacheKey);
        return Cache::get($this->cacheKey) === null;
    }

    /**
     * @throws Exception
     */
    public function isLatest($key, $update = true): bool
    {
        if ($this->noService()){
            return false;
        }
        $secrets = $this->fetchSecrets();
//        $retryKey = $this->retryCount . $key;
        $retryCount = $secrets->$key->retryCount;
//        $suspendedKeys = Cache::get($this->suspendedKeys);
//        Cache::put($retryKey,  1);
//        dd($retryCount);
//        dd($suspendedKeys) ;
//        if ($suspendedKeys === null){
//            $suspendedKeys = [];
//        }
        switch (true) {
            /** @noinspection PhpDuplicateSwitchCaseBodyInspection */ case !is_numeric($retryCount):
                $secrets->$key->retryCount = 1;
                $secrets->$key->status = 'failing';
                break;
            case $retryCount <= 10:
                $secrets->$key->retryCount++;
                $secrets->$key->status = 'failing';
                break;
            case $retryCount > 10:
                $secrets->$key->status = 'failed';
                $this->updateSecrets($secrets);
                return true;
            default:
                $secrets->$key->retryCount = 1;
                $secrets->$key->status = 'failing';
                break;
        }
        $this->updateSecrets($secrets);
        $aws = json_decode($this->fetchSecretsFromAWS());
        $result = $aws->$key === $secrets->$key->value;
        if (!$result && $update)
        {
            Cache::forget($this->cacheKey);
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    private function updateSecrets($secrets){
        foreach ($secrets as $key=>$value){
            $secrets->$key->value = Crypt::encryptString($value->value);
        }
        Cache::put($this->cacheKey, $secrets);
        $this->decryptSecrets($secrets);
    }

    /**
     * @throws Exception
     */
    private function fetchSecrets()
    {
        $secrets = Cache::rememberForever($this->cacheKey, function () {
            $awsKeys = json_decode($this->fetchSecretsFromAWS());
            $secrets = new stdClass();
            foreach ($awsKeys as $key=>$value){
                $secrets->$key = new stdClass();
                $secrets->$key->value = Crypt::encryptString($value);
                $secrets->$key->retryCount = 0;
                $secrets->$key->status = 'active';
            }
            return $secrets;
        });
        $this->decryptSecrets($secrets);
//        dd($secrets);
        return $secrets;
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

    private function decryptSecrets($secrets): void
    {
        foreach ($secrets as $key=>$value){
            try {
                $secrets->$key->value = Crypt::decryptString($value->value);
            } catch (DecryptException $e) {
                throw new Exception("SecretsDecryptionFailureException");
            }
        }
    }

    private function noService(): bool
    {
        if (env('APP_KEY') === '' || env('APP_KEY' === null)){
            return true;
        }
        return false;
    }
}
