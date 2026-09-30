<?php
declare(strict_types=1);

/**
 * FOSSBilling registrar adapter for Subreg.CZ.
 * Requires PHP SOAP extension.
 */
class Registrar_Adapter_Subreg extends Registrar_AdapterAbstract
{
    private string $login;
    private string $password;
    private string $productionUrl;
    private string $testUrl;
    private ?SoapClient $client = null;
    private ?string $ssid = null;

    public function __construct($options)
    {
        $this->login = trim((string)($options['login'] ?? ''));
        $this->password = (string)($options['password'] ?? '');
        $this->productionUrl = trim((string)($options['production_url'] ?? 'https://soap.subreg.cz/cmd.php'));
        $this->testUrl = trim((string)($options['test_url'] ?? 'https://soap.demoreg.net/cmd.php'));

        if ($this->login === '') {
            throw new Registrar_Exception('Subreg username is required.');
        }
        if ($this->password === '') {
            throw new Registrar_Exception('Subreg password is required.');
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Subreg.CZ',
            'form' => [
                'login' => ['text', ['label' => 'Subreg username']],
                'password' => ['password', ['label' => 'Subreg password']],
                'production_url' => ['text', [
                    'label' => 'Production SOAP URL',
                    'description' => 'Default: https://soap.subreg.cz/cmd.php',
                    'required' => false,
                ]],
                'test_url' => ['text', [
                    'label' => 'OT&E SOAP URL',
                    'description' => 'Default: https://soap.demoreg.net/cmd.php',
                    'required' => false,
                ]],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $response = $this->call('Check_Domain', ['domain' => $domain->getName()]);
        return (string)($response['data']['avail'] ?? '0') === '1';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        try {
            $response = $this->call('In_Subreg', ['domain' => $domain->getName()]);
            return (string)($response['data']['myaccount'] ?? 'no') !== 'yes';
        } catch (Registrar_Exception $e) {
            return true;
        }
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        return $this->makeOrder($domain->getName(), 'ModifyNS_Domain', [
            'ns' => ['hosts' => $this->nameservers($domain)],
        ]);
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $contact = $this->contactPayload($domain->getContactRegistrar());
        return $this->makeOrder($domain->getName(), 'Modify_Domain', [
            'registrant' => ['new' => $contact],
            'contacts' => [
                'admin' => ['new' => $contact],
                'tech' => ['new' => $contact],
                'billing' => ['new' => $contact],
            ],
        ]);
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $contact = $this->contactPayload($domain->getContactRegistrar());
        return $this->makeOrder($domain->getName(), 'Transfer_Domain', [
            'authid' => $domain->getEpp(),
            'new' => [
                'registrant' => ['new' => $contact],
                'admin' => ['new' => $contact],
                'tech' => ['new' => $contact],
            ],
        ]);
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $response = $this->call('Info_Domain', ['domain' => $domain->getName()]);
        $data = $response['data'] ?? [];

        if (isset($data['crDate'])) {
            $domain->setRegistrationTime($data['crDate']);
        }
        if (isset($data['exDate'])) {
            $domain->setExpirationTime($data['exDate']);
        }
        if (isset($data['authid'])) {
            $domain->setEpp((string)$data['authid']);
        }

        $hosts = $data['hosts'] ?? [];
        if (is_array($hosts)) {
            $ns = [];
            foreach ($hosts as $host) {
                if (is_string($host)) {
                    $ns[] = $host;
                } elseif (is_array($host)) {
                    $ns[] = (string)($host['hostname'] ?? $host['host'] ?? '');
                }
            }

            foreach (array_values(array_filter($ns)) as $i => $host) {
                $setter = 'setNs' . ($i + 1);
                if ($i < 4 && method_exists($domain, $setter)) {
                    $domain->{$setter}($host);
                }
            }
        }

        return $domain;
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $response = $this->call('Info_Domain', ['domain' => $domain->getName()]);
        $authid = $response['data']['authid'] ?? null;

        if (!is_string($authid) || $authid === '') {
            throw new Registrar_Exception('Subreg did not return an authorization code.');
        }

        return $authid;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $contact = ['new' => $this->contactPayload($domain->getContactRegistrar())];

        return $this->makeOrder($domain->getName(), 'Create_Domain', [
            'period' => $domain->getRegistrationPeriod(),
            'registrant' => $contact,
            'contacts' => [
                'admin' => $contact,
                'tech' => $contact,
                'billing' => $contact,
            ],
            'ns' => ['hosts' => $this->nameservers($domain)],
        ]);
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        return $this->makeOrder($domain->getName(), 'Renew_Domain', [
            'period' => $domain->getRegistrationPeriod(),
        ]);
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        return $this->makeOrder($domain->getName(), 'Delete_Domain', []);
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Subreg privacy protection is not implemented.');
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Subreg privacy protection is not implemented.');
    }

    public function lock(Registrar_Domain $domain): bool
    {
        return $this->setTransferLock($domain, true);
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        return $this->setTransferLock($domain, false);
    }

    public function isTestEnv()
    {
        return $this->_testMode;
    }

    private function setTransferLock(Registrar_Domain $domain, bool $locked): bool
    {
        $response = $this->call('Info_Domain', ['domain' => $domain->getName()]);
        $statuses = $response['data']['status'] ?? [];
        $statuses = is_array($statuses) ? array_values(array_filter(array_map('strval', $statuses))) : [];

        if ($locked && !in_array('clientTransferProhibited', $statuses, true)) {
            $statuses[] = 'clientTransferProhibited';
        }

        if (!$locked) {
            $statuses = array_values(array_filter(
                $statuses,
                static fn(string $s): bool => $s !== 'clientTransferProhibited'
            ));
        }

        return $this->makeOrder($domain->getName(), 'Modify_Domain', ['statuses' => $statuses]);
    }

    private function makeOrder(string $domain, string $type, array $params): bool
    {
        $response = $this->call('Make_Order', [
            'order' => [
                'domain' => $domain,
                'type' => $type,
                'params' => $params,
            ],
        ]);

        return ($response['status'] ?? null) === 'ok';
    }

    private function call(string $command, array $data): array
    {
        $client = $this->getClient();

        try {
            $payload = $command === 'Login'
                ? $data
                : array_merge(['ssid' => $this->getSsid()], $data);

            $response = $client->__call($command, ['data' => $payload]);
        } catch (Throwable $e) {
            throw new Registrar_Exception('Subreg API request failed: ' . $e->getMessage());
        }

        if (!is_array($response)) {
            throw new Registrar_Exception('Subreg returned an invalid API response.');
        }

        if (($response['status'] ?? null) !== 'ok') {
            $message = $response['error']['errormsg'] ?? 'Unknown Subreg API error';
            throw new Registrar_Exception('Subreg API error: ' . $message);
        }

        return $response;
    }

    private function getSsid(): string
    {
        if ($this->ssid !== null) {
            return $this->ssid;
        }

        $response = $this->call('Login', [
            'login' => $this->login,
            'password' => $this->password,
        ]);

        $ssid = $response['data']['ssid'] ?? null;
        if (!is_string($ssid) || $ssid === '') {
            throw new Registrar_Exception('Subreg login returned no SSID.');
        }

        return $this->ssid = $ssid;
    }

    private function getClient(): SoapClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!extension_loaded('soap')) {
            throw new Registrar_Exception('PHP SOAP extension is required.');
        }

        $this->client = new SoapClient(null, [
            'location' => $this->isTestEnv() ? $this->testUrl : $this->productionUrl,
            'uri' => 'http://soap.subreg',
            'exceptions' => true,
            'trace' => false,
            'connection_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);

        return $this->client;
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        $hosts = [];

        foreach (['getNs1', 'getNs2', 'getNs3', 'getNs4'] as $getter) {
            if (method_exists($domain, $getter)) {
                $host = trim((string)$domain->{$getter}());
                if ($host !== '') {
                    $hosts[] = ['hostname' => $host];
                }
            }
        }

        return $hosts;
    }

    private function contactPayload($contact): array
    {
        $parts = preg_split('/\s+/u', trim((string)$contact->getName()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $surname = array_pop($parts) ?: 'Contact';
        $name = trim(implode(' ', $parts)) ?: $surname;

        $payload = [
            'name' => $name,
            'surname' => $surname,
            'street' => trim((string)$contact->getAddress1()),
            'city' => trim((string)$contact->getCity()),
            'pc' => trim((string)$contact->getZip()),
            'cc' => strtoupper(trim((string)$contact->getCountry())),
            'phone' => trim((string)$contact->getTel()),
            'email' => trim((string)$contact->getEmail()),
        ];

        if (method_exists($contact, 'getCompany')) {
            $company = trim((string)$contact->getCompany());
            if ($company !== '') {
                $payload['org'] = $company;
            }
        }

        if (method_exists($contact, 'getState')) {
            $state = trim((string)$contact->getState());
            if ($state !== '') {
                $payload['sp'] = $state;
            }
        }

        return $payload;
    }
}
