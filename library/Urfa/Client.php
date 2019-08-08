<?php

class Urfa_Client
{
    protected $urfa;

    protected $config;

    const FLAG_TO_POSITIVE_BALANCE = 1;

    public function __construct($host = null, $port = null, $ssl = false)
    {
        if (is_null($host) || is_null($port)) {
            $this->config = new Zend_Config_Ini(APPLICATION_PATH . '/configs/billing.ini', 'app');
            $host = $this->config->urfaphp->host;
            $port = $this->config->urfaphp->port;
        }
        $this->urfa = new Urfa_Connect();
        if (!$this->urfa->connect($host, $port, $ssl)) {
            throw new Urfa_Exception('Не возможно подключиться к биллингу. Попробуйте войти позже.');
        }
    }

    /**
     * Функция авторизации
     * @param        $login
     * @param        $password
     * @param bool $service
     * @param string $server
     *
     * @return array|bool
     * @throws Urfa_Exception
     */
    public function login($login, $password, $service = true, $server = null)
    {
        if (is_null($server)) {
            $server = $_SERVER['REMOTE_ADDR'];
        }
        if (!$server) {
            throw new Urfa_Exception('Не возможно получить ваш IP адрес', 500);
        }
        if ($this->urfa->open_session($login, $password, $service, $server) != false && $this->urfa->call(-0x4052)
            && $this->urfa->send()
        ) {

            $data['utm5'] = $this->urfa->get_key();

            $this->urfa->close_session();
            $this->urfa->disconnect();

            return $data;
        } else {
            return false;
        }
    }

    /**
     * @param      $login    Логин пользователя
     * @param      $password Пароль пользователя
     * @param bool $service
     * @param bool $client_ip
     *
     * @return bool
     */
    public function open_session($login, $password, $service = true, $client_ip = false)
    {
        $open_session = $this->urfa->open_session($login, $password, $service, $client_ip);
        if (!$open_session) {
            throw new Urfa_Exception('Не возможно открыть сессию', 500);
        }

        return $open_session;
    }

    /**
     * Восстановление сессии
     * @param $session_id
     * @param $client_ip
     *
     * @return bool
     */
    public function restore_session($session_id, $client_ip = null)
    {
        if (is_null($client_ip)) {
            $client_ip = $_SERVER['REMOTE_ADDR'];
        }
        $restore_session = $this->urfa->restore_session(
            $this->config->urfaphp->login,
            $this->config->urfaphp->password,
            $session_id,
            $client_ip
        );
        if (!$restore_session) {
            // $this->bootstrapView();
            // $view = $this->getResource('view');
            //  $view->flashMessenger->addMessage(array('error' => 'Закончилось время сессии'));

            $_auth = Zend_Auth::getInstance();
            $_auth->clearIdentity();

            $front = Zend_Controller_Front::getInstance();
            //$front->_helper->flashMessenger->addMessage(array('error' => 'Закончилось время сессии'));
            $response = new Zend_Controller_Response_Http();
            $response->setRedirect('/logout');
            $front->setResponse($response);
        }

        return $restore_session;
    }

    public function __destruct()
    {
        unset($this->urfa);
    }

    /**
     * Общий отчёт по трафику
     * @param $startDay
     * @param $endDay
     *
     * @return array|null
     */
    public function get_traffic_report($startDay, $endDay)
    {
        $report = array();

        $this->urfa->call(-16393);
        $this->urfa->put_int(intval($startDay));
        $this->urfa->put_int(intval($endDay));

        $this->urfa->send();
        $unused = $this->urfa->get_int();
        $bytes_in_kb = $this->urfa->get_double();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['tclass'] = $this->urfa->get_int();
            $tmp['tclass_name'] = $this->urfa->get_string();
            $tmp['bytes'] = $this->urfa->get_long();
            $tmp['mbytes'] = $tmp['bytes'] / ($bytes_in_kb * $bytes_in_kb);
            $tmp['base_cost'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $tmp['discount'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $tmp['discount_with_tax'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $report[$i] = $tmp;
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Отчёт по трафику по дням
     * @param $startDay
     * @param $endDay
     *
     * @return array|null
     */
    public function get_traffic_report_by_date($startDay, $endDay)
    {

        $report = array();

        $this->urfa->call(-0x4010);
        $this->urfa->put_int(intval($startDay));
        $this->urfa->put_int(intval($endDay));
        $this->urfa->put_int(0);

        $this->urfa->send();

        $cnt = 0;

        $unused = $this->urfa->get_int();
        $unused = $this->urfa->get_int();
        $bytes_in_kb = $this->urfa->get_double();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $date = $this->urfa->get_int();
            $count2 = $this->urfa->get_int();
            for ($i2 = 0; $i2 < $count2; $i2++) {
                $tmp = array();
                $tmp['date'] = Urfa_Resolve::getDateFromTimestamp($date);
                $tmp['tclass'] = $this->urfa->get_int();
                $tmp['tclass_name'] = $this->urfa->get_string();
                $tmp['bytes'] = $this->urfa->get_long();
                $tmp['mbytes'] = $tmp['bytes'] / ($bytes_in_kb * $bytes_in_kb);
                $tmp['base_cost'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['discount'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $report[$cnt++] = $tmp;
            }
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Отчёт по трафику по ip
     *
     * @param $startDay
     * @param $endDay
     *
     * @return array|null
     */
    public function get_traffic_report_by_ip($startDay, $endDay)
    {
        $report = array();

        $this->urfa->call(-16460);
        $this->urfa->put_int(intval($startDay));
        $this->urfa->put_int(intval($endDay));

        $this->urfa->send();

        $cnt = 0;

        $unused = $this->urfa->get_int();
        $unused = $this->urfa->get_int();
        $bytes_in_kb = $this->urfa->get_double();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            //$ip = $this->urfa->get_int();
            $ip = $this->urfa->get_ip_address();

            $count2 = $this->urfa->get_int();
            for ($i2 = 0; $i2 < $count2; $i2++) {
                $tmp = array();
                //$tmp['ip'] = Urfa_Resolve::ip2string($ip);
                $tmp['ip'] = $ip->toString();

                $tmp['tclass'] = $this->urfa->get_int();
                $tmp['tclass_name'] = $this->urfa->get_string();
                $tmp['bytes'] = $this->urfa->get_long();
                $tmp['mbytes'] = $tmp['bytes'] / ($bytes_in_kb * $bytes_in_kb);
                $report[$cnt++] = $tmp;
            }
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Отчёт по платежам
     * @param $startDay
     * @param $endDay
     *
     * @return array|null
     */
    public function get_payments_report($startDay, $endDay)
    {
        $currency = array();

        $this->urfa->call(-0x4037);
        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $id = $this->urfa->get_int();
            $this->urfa->get_string();
            $name = $this->urfa->get_string();
            $this->urfa->get_double();
            $this->urfa->get_double();

            $currency[$id] = $name;
        }

        $this->urfa->finish();

        $report = array();

        $this->urfa->call(-16409);
        $this->urfa->put_int(intval($startDay));
        $this->urfa->put_int(intval($endDay));
        $this->urfa->send();

        $accounts_number = $this->urfa->get_int();

        for ($j = 0; $j < $accounts_number; $j++) {

            $count = $this->urfa->get_int();
            $account_report = array();

            for ($i = 0; $i < $count; $i++) {
                //$tmp = array();
                $tmp['account'] = $this->urfa->get_int();
                $tmp['actual_payment_date_unix'] = $this->urfa->get_int();
                $tmp['actual_payment_date'] = Urfa_Resolve::getDateFromTimestamp($tmp['actual_payment_date_unix']);
                $tmp['date_of_payment_unix'] = $this->urfa->get_int();
                $tmp['date_of_payment'] = Urfa_Resolve::getDateFromTimestamp($tmp['date_of_payment_unix']);
                $tmp['volume'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['payment_incurrency'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['currency'] = $currency[$this->urfa->get_int()];
                $tmp['payment_method_id'] = $this->urfa->get_int();
                $tmp['payment_method'] = $this->urfa->get_string();
                $tmp['comment'] = $this->urfa->get_string();
                $account_report[$i] = $tmp;
            }
            $report[$j] = $account_report;
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Отчёт о сервисах
     * @param $startDay
     * @param $endDay
     *
     * @return array|null
     */
    public function get_service_report($startDay, $endDay)
    {
        $report = array();
        $iter = 0;

        $this->urfa->call(-16401);
        $this->urfa->put_int(intval($startDay));
        $this->urfa->put_int(intval($endDay));


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $count2 = $this->urfa->get_int();
            for ($i2 = 0; $i2 < $count2; $i2++) {
                $tmp = array();
                $tmp['account_id'] = $this->urfa->get_int();
                $tmp['charged_on_unix'] = $this->urfa->get_int();
                $tmp['charged_on'] = Urfa_Resolve::getDateFromTimestamp($tmp['charged_on_unix']);
                $tmp['amount'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['amount_with_tax'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['service_name'] = $this->urfa->get_string();
                $tmp['service_type'] = Urfa_Resolve::resolveServiceType($this->urfa->get_int());
                $tmp['comment'] = $this->urfa->get_string();
                $report[$iter++] = $tmp;
            }
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Получааем информацию о тарифах пользователя
     * @return array|null
     */
    public function getTarrifs()
    {
        $accounts = null;
        $additional = null;
        $this->urfa->call(-0x403b);
        $this->urfa->send();
        $this->additional = $this->urfa->get_int();
        $this->urfa->finish();

        $accounts = $this->getAccounts();

        $tariffs = null;

        foreach ($accounts as $prop => $val) {
            $tariffs[$val] = $this->getTariffInfo($val);
        }

        return $tariffs;
    }

    /**
     * Получаем аккаунты пользователя
     * @return array
     */
    public function getAccounts()
    {
        $accounts = array();
        $this->urfa->call(-16469);
        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $aid = $this->urfa->get_int();

            $this->urfa->get_double();
            $this->urfa->get_double();

            $accounts[$aid] = $aid;
        }
        $this->urfa->finish();

        return $accounts;
    }

    /**
     * Получение информации о тарифах пользователя
     * Если передаётся параметр $tlink_id, то возвращается информация только об этом тарифе
     * @param     $aid
     * @param int $tlink_id
     *
     * @return array
     */
    public function getTariffInfo($aid, $tlink_id = false)
    {
        $aid_tariffs = array();

        $this->urfa->call(-0x15004);
        $this->urfa->put_int($aid);
        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['aid'] = $aid;
            $tmp['id'] = $this->urfa->get_int();
            $tmp['cur_tp_id'] = $this->urfa->get_int();
            $tmp['cyr_tp_name'] = $this->urfa->get_string();
            $tmp['cyr_tp_descr'] = $this->urfa->get_string();
            $tmp['next_tp_id'] = $this->urfa->get_int();
            $tmp['next_tp_name'] = $this->urfa->get_string();
            $tmp['next_tp_descr'] = $this->urfa->get_string();
            $tmp['ap_id'] = $this->urfa->get_int();
            $tmp['ap_id_start'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['ap_id_end'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['link'] = Urfa_Resolve::getLinkToTariff($aid, $tmp['id']);

            if ($tlink_id) {
                if ($tmp['id'] == $tlink_id) {
                    $aid_tariffs = $tmp;
                }
            } else {
                $aid_tariffs[$i] = $tmp;
            }
        }
        $this->urfa->finish();

        return $aid_tariffs;
    }

    /**
     * @TODO Описание и название функции
     *
     * @param $aid
     * @param $tlink_id
     *
     * @return array
     */
    public function changeTariffInfo($aid, $tlink_id)
    {
        $data = array();

        $this->urfa->call(-0x15005);
        $this->urfa->put_int($aid);
        $this->urfa->put_int($tlink_id);
        $this->urfa->send();

        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['id'] = $this->urfa->get_int();
            $tmp['name'] = $this->urfa->get_string();
            $tmp['comments'] = $this->urfa->get_string();
            $tmp['min_balance'] = $this->urfa->get_double();
            $tmp['use_min_balance'] = $this->urfa->get_int();
            $tmp['free_balance'] = $this->urfa->get_double();
            $tmp['use_free_balance'] = $this->urfa->get_int();
            $tmp['cost'] = $this->urfa->get_double();
            $tmp['can_change'] = $this->urfa->get_int();
            // $tmp['link'] = getLinkToChangeTariff($this->aid, $this->tlink_id, $tmp['id']);
            $data['tariff'][$i] = $tmp;
        }

        $data['balance'] = $this->urfa->get_double();
        $this->urfa->finish();

        return $data;
    }

    /**
     * Функция для изменения тарифа
     * @param $aid
     * @param $tlink_id
     * @param $tp_next
     *
     * @return bool
     */
    public function changeTariff($aid, $tlink_id, $tp_next)
    {
        $this->urfa->call(-0x15006);
        $this->urfa->put_int($aid);
        $this->urfa->put_int($tlink_id);
        $this->urfa->put_int($tp_next);
        $this->urfa->send();
        $result_tc = $this->urfa->get_int();

        $this->urfa->finish();
        $this->urfa->close_session(false);
        $this->urfa->disconnect();

        return $result_tc;
    }

    /**
     * История изменения тарифов для пользователя
     */
    public function getTariffHistory()
    {
        $this->urfa->call(-16469);
        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $aid = $this->urfa->get_int();

            $this->urfa->get_double();
            $this->urfa->get_double();

            $this->accounts[$aid] = $aid;
        }
        $this->urfa->finish();

        $tariffs = array();

        foreach ($this->accounts as $prop => $val) {
            $aid_tariffs = array();

            $this->urfa->call(-0x15026);
            $this->urfa->put_int($val);
            $this->urfa->send();

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();
                $tmp['id'] = $this->urfa->get_int();
                $tmp['link_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
                $tmp['unlink_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
                $tmp['name'] = $this->urfa->get_string();
                $aid_tariffs[$i] = $tmp;
            }
            $this->urfa->finish();
            $tariffs[$val] = $aid_tariffs;
        }

        return $tariffs;
    }

    /**
     * Получаем информацию о сервисах пользователя
     * @return array|null
     */
    public function getServices()
    {
        $services = false;
        $this->urfa->call(-0x402f);
        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['id'] = $this->urfa->get_int();
            $tmp['service_id'] = $this->urfa->get_int();
            $tmp['service_type'] = $this->urfa->get_int();
            $tmp['service_type_name'] = Urfa_Resolve::resolveServiceType($tmp['service_type']);
            $tmp['service_name'] = $this->urfa->get_string();

            if ($tmp['service_type'] > 2) {
                $tmp['link'] = Urfa_Resolve::getLinkToService($tmp['id'], $tmp['service_name']);
            } else {
                $tmp['link'] = $tmp['service_name'];
            }
            $tmp['tariff_name'] = $this->urfa->get_string();
            $tmp['discount_period_start'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['discount_period_end'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['cost'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $tmp['discounted_in_curr_period'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $services[$i] = $tmp;
        }
        $this->urfa->finish();

        return $services;
    }

    /**
     * Получааем информацтю о пользователе
     * @return array
     */
    public function getUserInfo()
    {
        $user = array();
        $this->urfa->call(-0x4052);
        $this->urfa->send();

        $user['id'] = $this->urfa->get_int();
        $user['login'] = $this->urfa->get_string();
        $user['basic_account'] = $this->urfa->get_int();
        $user['balance'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
        $user['credit'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
        $user['is_blocked_int'] = $this->urfa->get_int();
        $user['is_blocked'] = Urfa_Resolve::resolveBlockState($user['is_blocked_int']);
        $user['create_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
        $user['last_change_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
        $user['who_create'] = Urfa_Resolve::resolveUserName($this->urfa->get_int());
        $user['who_change'] = Urfa_Resolve::resolveUserName($this->urfa->get_int());
        $user['is_juridical'] = $this->urfa->get_int();
        $user['full_name'] = $this->urfa->get_string();
        $user['juridical_address'] = $this->urfa->get_string();
        $user['actual_address'] = $this->urfa->get_string();
        $user['work_telephone'] = $this->urfa->get_string();
        $user['home_telephone'] = $this->urfa->get_string();
        $user['mobile_telephone'] = $this->urfa->get_string();
        $user['web_page'] = $this->urfa->get_string();
        $user['icq'] = $this->urfa->get_string();
        $user['tax'] = $this->urfa->get_string();
        $user['kpp'] = $this->urfa->get_string();
        $user['bank_id'] = $this->urfa->get_int();
        $user['user_bank_account'] = $this->urfa->get_string();
        $user['int_status'] = Urfa_Resolve::resolveIntStatus($this->urfa->get_int());
        $user['vat_rate'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
        $user['passport'] = $this->urfa->get_string();

        $this->urfa->finish();

        return $user;
    }

    /**
     * Информация об лицевых счетах пользователя
     * @return array
     */
    public function getAccountsInfo()
    {
        $accounts = array();
        $this->urfa->call(-0x15028);

        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $account_id = $this->urfa->get_int();
            $tmp['balance'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $tmp['credit'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $tmp['int_status'] = $this->urfa->get_int();
            $tmp['block_status'] = $this->urfa->get_int();
            $tmp['vat_rate'] = $this->urfa->get_double();
            //$tmp['locked_in_funds'] = -1.0 * Urfa_Resolve::roundDouble($this->urfa->get_double());
            //$tmp['link'] = resolveIntStatusForAccount($tmp['int_status'], $account_id);
            $this->urfa->get_double();//unused
            $accounts[$account_id] = $tmp;
        }
        $this->urfa->finish();

        return $accounts;
    }

    public function changeStatus($acc_id, $new_int_status_acc)
    {

        $this->urfa->call(-0x4049);
        $this->urfa->put_int($acc_id);
        $this->urfa->put_int($new_int_status_acc);
        $this->urfa->send();
        $this->urfa->finish();
        $this->urfa->close_session(false);
        $this->urfa->disconnect();
    }

    /**
     * Получаем информацию о сервисе
     * @param $slink_id
     *
     * @return array|null
     */
    public function getServiceInfo($slink_id)
    {
        if (!defined("SLINK_SHAPING_AVAILABLE")) {
            define("SLINK_SHAPING_AVAILABLE", 1);
            define("SLINK_SHAPING_TURBO_MODE_AVAILABLE", 2);
            define("SLINK_SHAPING_INCOMING", 4);
            define("SLINK_SHAPING_OUTGOING", 8);
        }
        $slink_id = intval($slink_id);
        $report = null;

        $service = array();

        // $this->urfa->call(-16420);
        $this->urfa->call(-0x404a);

        $this->urfa->put_int($slink_id);
        $this->urfa->send();

        $service['type'] = $this->urfa->get_int();
        $service['type_name'] = Urfa_Resolve::resolveServiceType($service['type']);
        $service['id'] = $this->urfa->get_int();
        $service['name'] = $this->urfa->get_string();
        $service['tariff_id'] = $this->urfa->get_int();
        $service['discounted_in_curr_period'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
        $service['cost_in_period'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
        $report['service'] = $service;
        if ($service['type'] == 3) {
            $downloaded = array();
            $transfered = array();
            $ip_groups = array();
            $borders = array();
            $prepaid = array();

            $bytes_in_mb = $this->urfa->get_int();

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();

                $tmp['tclass'] = $this->urfa->get_string();
                $tmp['bytes'] = $this->urfa->get_long();
                $tmp['mbytes'] = Urfa_Resolve::roundDouble($tmp['bytes'] / $bytes_in_mb);
                $downloaded[$i] = $tmp;
            }
            $report['downloaded'] = $downloaded;

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();

                $tmp['tclass'] = $this->urfa->get_string();
                $tmp['bytes'] = $this->urfa->get_long();
                $tmp['mbytes'] = Urfa_Resolve::roundDouble($tmp['bytes'] / $bytes_in_mb);
                $transfered[$i] = $tmp;
            }
            $report['transfered'] = $transfered;

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();

                $tmp['id'] = $this->urfa->get_int();
                //$tmp['ip'] = Urfa_Resolve::ip2string($this->urfa->get_int());
                // $tmp['mask'] = Urfa_Resolve::ip2string($this->urfa->get_int());
                $tmp['ip'] = $this->urfa->get_ip_address()->toString();
                $tmp['mask'] = $this->urfa->get_int();
                $tmp['login'] = $this->urfa->get_string();
                //$tmp['link'] = getLinkToServicePass($this->slink_id, $tmp['id'], langGet("change_password"));
                $tmp['link'] = Urfa_Resolve::getLinkToServicePass($slink_id, $tmp['id'], 'Изменить пароль', $tmp['login']);
                $ip_groups[$i] = $tmp;
            }
            $report['ip_groups'] = $ip_groups;

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();

                $tmp['tclass'] = $this->urfa->get_string();
                $tmp['bytes'] = $this->urfa->get_long();
                $tmp['mbytes'] = Urfa_Resolve::roundDouble($tmp['bytes'] / $bytes_in_mb);
                $tmp['cost'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['group_type'] = $this->urfa->get_int();
                $borders[$i] = $tmp;
            }
            $report['borders'] = $borders;

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();

                $tmp['tclass'] = $this->urfa->get_string();
                $tmp['bytes'] = $this->urfa->get_long();
                $tmp['mbytes'] = Urfa_Resolve::roundDouble($tmp['bytes'] / $bytes_in_mb);
                $prepaid[$i] = $tmp;
            }
            $report['prepaid'] = $prepaid;

        } elseif ($service['type'] == 6) {
            $telephones = array();

            $count = $this->urfa->get_int();
            for ($i = 0; $i < $count; $i++) {
                $tmp = array();

                $tmp['number'] = $this->urfa->get_string();
                $tmp['login'] = $this->urfa->get_string();
                $tmp['allowed_cid'] = $this->urfa->get_string();
                $tmp['id'] = $this->urfa->get_int();
                $tmp['link'] = Urfa_Resolve::getLinkToServicePass($slink_id, $tmp['id'], 'Изменить пароль');
                $telephones[$i] = $tmp;
            }
            $report['telephones'] = $telephones;

        } else {
            $this->urfa->get_int();
        }
        $this->urfa->finish();

        if ($service['type'] == 3) {
            //$this->urfa->call(-0x12009);
            $this->urfa->call(-0x12010);
            $this->urfa->put_int($slink_id);
            $this->urfa->send();

            $retCode = $this->urfa->get_int();
            if ($retCode != 0) {
                return $report;
            }

            $flags = $this->urfa->get_int();
            $shaping['turbomode_settings_id'] = $this->urfa->get_int();
            $shaping['incoming_rate'] = $this->urfa->get_int();
            $shaping['outgoing_rate'] = $this->urfa->get_int();
            $shaping['turbo_mode_start'] = $this->urfa->get_int();
            $shaping['turbo_mode_end'] = $this->urfa->get_int();
            $shaping['incoming_consumption_left'] = $this->urfa->get_long();
            $shaping['outgoing_consumption_left'] = $this->urfa->get_long();
            $this->urfa->finish();

            $shaping['show_shaping'] = (($flags & SLINK_SHAPING_AVAILABLE) != 0) ? true : false;
            $shaping['turbo_mode_available'] = (($flags & SLINK_SHAPING_TURBO_MODE_AVAILABLE) != 0) ? true : false;

            if ($shaping['incoming_rate'] == 0) {
                $shaping['incoming_rate'] = $shaping['outgoing_rate'];
            }
            if ($shaping['outgoing_rate'] == 0) {
                $shaping['outgoing_rate'] = $shaping['incoming_rate'];
            }
            $shaping['incoming_rate'] = Urfa_Resolve::resolveRate($shaping['incoming_rate']);
            $shaping['outgoing_rate'] = Urfa_Resolve::resolveRate($shaping['outgoing_rate']);

            $report['shaping'] = $shaping;

            if ($shaping['turbo_mode_available'] == true) {
                // $this->urfa->call(-0x1200b);
                $this->urfa->call(-0x1200c);
                $this->urfa->put_int($slink_id);
                $this->urfa->send();

                $modesCnt = $this->urfa->get_int();

                $modes = array();

                for ($m = 0; $m < $modesCnt; $m++) {
                    $modes[$m] = array();
                    $modes[$m]['id'] = $this->urfa->get_int();
                    $modes[$m]['name'] = $this->urfa->get_string();
                    $modes[$m]['incoming_rate'] = $this->urfa->get_int();
                    $modes[$m]['outgoing_rate'] = $this->urfa->get_int();

                    if ($modes[$m]['incoming_rate'] == 0) {
                        $modes[$m]['incoming_rate'] = $modes[$m]['outgoing_rate'];
                    }

                    if ($modes[$m]['outgoing_rate'] == 0) {
                        $modes[$m]['outgoing_rate'] = $modes[$m]['incoming_rate'];
                    }

                    $isDuration = $this->urfa->get_int();
                    if ($isDuration) {
                        $modes[$m]['duration'] = $this->urfa->get_int();
                    } else {
                        $modes[$m]['incoming_limit'] = $this->urfa->get_long();
                        $modes[$m]['outgoing_limit'] = $this->urfa->get_long();
                    }

                    $modes[$m]['cost'] = $this->urfa->get_double();
                }

                $this->urfa->finish();

                $turbo = array();
                for ($m = 0; $m < $modesCnt; $m++) {
                    if ($modes[$m]['id'] == $shaping['turbomode_settings_id']) {
                        continue;
                    }

                    if ($this->checkRate($modes[$m], $shaping)) {
                        continue;
                    }

                    $turbo[$m] = $modes[$m];
                }


                /* $turbo = array();
                 $turbo['incoming_rate'] = $this->urfa->get_int();
                 $turbo['outgoing_rate'] = $this->urfa->get_int();
                 $turbo['duration'] = Urfa_Resolve::getTimeFromSec($this->urfa->get_int());
                 $turbo['cost'] = $this->urfa->get_double();
                 $this->urfa->finish();
                 if ($turbo['incoming_rate'] == 0) {
                     $turbo['incoming_rate'] = $turbo['outgoing_rate'];
                 }
                 if ($turbo['outgoing_rate'] == 0) {
                     $turbo['outgoing_rate'] = $turbo['incoming_rate'];
                 }

                 $turbo['incoming_rate'] = Urfa_Resolve::resolveRate($turbo['incoming_rate']);
                 $turbo['outgoing_rate'] = Urfa_Resolve::resolveRate($turbo['outgoing_rate']);
                 $turbo['link'] = Urfa_Resolve::getLinkToTurboMode($slink_id);*/

                $report['turbo'] = $turbo;
            }
        }

        // $this->urfa->finish();

        return $report;
    }


    private function checkRate($Rate, $shaping)
    {

        $incState = false;
        if ($shaping['incoming_rate'] == -1) {
            $incState = true;
        } else {
            if ($Rate['incoming_rate'] == -1) {
                $incState = false;
            } else {
                $incState = $shaping['incoming_rate'] > $Rate['incoming_rate'];
            }
        }

        $outgState = false;
        if ($shaping['outgoing_rate'] == -1) {
            $outgState = true;
        } else {
            if ($Rate['outgoing_rate'] == -1) {
                $outgState = false;
            } else {
                $outgState = $shaping['outgoing_rate'] > $Rate['outgoing_rate'];
            }
        }

        return $incState && $outgState;
    }

    /**
     * Получаем информацию о сообщении
     * @param $id
     *
     * @return array|null
     */
    public function getMessage($id)
    {
        $message = array();

        $this->urfa->call(-0x4042);
        $this->urfa->put_int($id);
        $this->urfa->send();

        $message['subject'] = $this->urfa->get_string();
        $message['message'] = $this->urfa->get_string();
        $message['mime'] = $this->urfa->get_string();
        $message['send_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
        $message['sender_id'] = $this->urfa->get_int();
        $message['sender'] = Urfa_Resolve::getSenderName($message['sender_id']);
        $this->urfa->finish();

        return $message;
    }

    public function getNewMessages($startDay, $endDay)
    {
        $report = array();

        $this->urfa->call(-0x4046);
        $this->urfa->put_int($startDay);
        $this->urfa->put_int($endDay);


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['id'] = $this->urfa->get_int();
            $tmp['send_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['send_date'] = "<b>" . $tmp['send_date'] . "</b>";
            $tmp['sender_id'] = $this->urfa->get_int();
            $tmp['subject'] = $this->urfa->get_string();
            $tmp['mime'] = $this->urfa->get_string();
            //$tmp['link'] = getMessageLink($tmp['id'], $tmp['subject'], 1);
            $report[$i] = $tmp;
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Получаем список сообщений
     * @param $startDay
     * @param $endDay
     *
     * @return array|null
     */
    public function getMessages($startDay, $endDay)
    {
        $report = array();

        $this->urfa->call(-16451);
        $this->urfa->put_int($startDay);
        $this->urfa->put_int($endDay);


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['id'] = $this->urfa->get_int();
            $tmp['send_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['sender_id'] = $this->urfa->get_int();
            $tmp['subject'] = $this->urfa->get_string();
            $tmp['mime'] = $this->urfa->get_string();
            $tmp['is_new'] = $this->urfa->get_int();
            if ($tmp['is_new']) {
                $tmp['send_date'] = "<b>" . $tmp['send_date'] . "</b>";
            }
            //  $tmp['link'] = getMessageLink($tmp['id'], $tmp['subject'], $tmp['is_new']);
            $report[$i] = $tmp;
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Отправляем сообщение
     * @param $subject
     * @param $message
     */
    public function sendMessage($subject, $message)
    {
        $this->urfa->call(-16405);
        $this->urfa->put_string($subject);
        $this->urfa->put_string($message);
        $this->urfa->send();
        $this->urfa->finish();
    }

    public function getSentMessages($startDay, $endDay)
    {

        $report = array();
        $this->urfa->call(-16452);
        $this->urfa->put_int($startDay);
        $this->urfa->put_int($endDay);


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['id'] = $this->urfa->get_int();
            $tmp['send_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['subject'] = $this->urfa->get_string();
            //   $tmp['link'] = getMessageLink($tmp['id'], $tmp['subject'], 0);
            $report[$i] = $tmp;
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Функция для измененния пароля к личному кабинету
     * @param $old_password
     * @param $new_password
     * @param $new_password_repeat
     *
     * @return bool
     */
    public function changePasswordForCabinet($old_password, $new_password, $new_password_repeat)
    {
        $this->urfa->call(-16417);
        $this->urfa->put_string((string)$old_password);
        $this->urfa->put_string((string)$new_password);
        $this->urfa->put_string((string)$new_password_repeat);
        $this->urfa->send();
        $result = $this->urfa->get_int();
        $this->urfa->finish();

        return $result;
    }

    /**
     * Функция для измененния пароля к услуге
     * @param $slink_id
     * @param $item_id
     * @param $old_password
     * @param $new_password
     * @param $new_password_repeat
     *
     * @return bool
     */
    public function changePassword($slink_id, $item_id, $old_password, $new_password, $new_password_repeat)
    {
        $this->urfa->call(-16421);
        $this->urfa->put_int((int)$slink_id);
        $this->urfa->put_int((int)$item_id);
        $this->urfa->put_string((string)$old_password);
        $this->urfa->put_string((string)$new_password);
        $this->urfa->put_string((string)$new_password_repeat);
        $this->urfa->send();
        $result = $this->urfa->get_int();
        $this->urfa->finish();

        return $result;
    }

    /**
     * Добавляем обещанный платёж
     * @param int $aid
     * @param     $amount
     *
     * @return bool
     */
    public function addPromisePayment($aid, $amount)
    {
        $this->urfa->call(-0x15025);
        $this->urfa->put_int($aid);
        $this->urfa->put_double((double)$amount);
        $this->urfa->send();
        $result_pp = $this->urfa->get_int();
        if(4294967295 === $result_pp) //64 bit PHP problem (see mantis #2575)
            $result_pp = -1;
        $this->urfa->finish();
        return $result_pp;
    }

    /**
     * Информация об обещанном платеже
     * @param int $aid
     *
     * @return array
     */
    public function getPromisePaymentInfo($aid)
    {
        //$this->urfa->call(-0x15024);
        $this->urfa->call(-0x15031);
        $this->urfa->put_int($aid);
        $this->urfa->send();

        $pp = array();
        $pp['can_change'] = $this->urfa->get_int();
        if ($pp['can_change'] >= 0) {
            $pp['last_payment_date'] = $this->urfa->get_int();
            $pp['value'] = $this->urfa->get_double();
            $pp['duration'] = $this->urfa->get_int();
            $pp['interval'] = $this->urfa->get_int();
            $pp['cost'] = $this->urfa->get_double();
            $pp['min_balance'] = $this->urfa->get_double();
            $pp['use_min_balance'] = $this->urfa->get_int();
            $pp['free_balance'] = $this->urfa->get_double();
            $pp['use_free_balance'] = $this->urfa->get_int();
            $pp['balance'] = $this->urfa->get_double();
            $pp['flags'] = $this->urfa->get_int();
        }
        $this->urfa->finish();

        return $pp;
    }

    /**
     * Дополнительные услуги
     * @return bool
     */
    public function getAdditional()
    {
        $this->urfa->call(-0x403b);
        $this->urfa->send();
        $additional = $this->urfa->get_int();
        $this->urfa->finish();

        return $additional;
    }

    /**
     * Информация о добровольной блокировке
     * @param $aid
     *
     * @return array
     */
    public function getBlockInfo($aid)
    {
        $this->urfa->call(-0x15014);
        $this->urfa->put_int($aid);
        $this->urfa->send();

        $vs = array();
        $vs['is_blocked'] = $this->urfa->get_int();
        if ($vs['is_blocked'] == 1) {
            $vs['block_start'] = $this->urfa->get_int();
            $vs['block_end'] = $this->urfa->get_int();
            $vs['can_unblock'] = $this->urfa->get_int();
        } elseif ($vs['is_blocked'] == 0) {
            $vs['can_set_block'] = $this->urfa->get_int();
            $vs['last_block_date'] = $this->urfa->get_int();
            $vs['min_duration'] = $this->urfa->get_int();
            $vs['max_duration'] = $this->urfa->get_int();
            $vs['interval'] = $this->urfa->get_int();
            $vs['block_type'] = $this->urfa->get_int();
            $vs['min_balance'] = $this->urfa->get_double();
            $vs['use_min_balance'] = $this->urfa->get_int();
            $vs['free_balance'] = $this->urfa->get_double();
            $vs['use_free_balance'] = $this->urfa->get_int();
            $vs['can_unblock'] = $this->urfa->get_int();
            $vs['cost'] = $this->urfa->get_double();
            $vs['balance'] = $this->urfa->get_double();
        }
        $this->urfa->finish();

        return $vs;
    }

    /**
     * Установить добровольную блокировку
     * @param $start
     * @param $end
     *
     * @return bool
     */
    public function setBlock($start, $end, $aid)
    {
        $this->urfa->call(-0x15015);
        $this->urfa->put_int($aid);
        $this->urfa->put_int((int)$start);
        $this->urfa->put_int((int)$end);
        $this->urfa->send();
        $result_vs = $this->urfa->get_int();
        $this->urfa->finish();

        return $result_vs;
    }

    /**
     * убрать добровольную блокировку
     * @param $aid
     *
     * @return bool
     */
    public function delBlock($aid)
    {
        $this->urfa->call(-0x15016);
        $this->urfa->put_int($aid);
        $this->urfa->send();
        $result_vs = $this->urfa->get_int();
        $this->urfa->finish();

        return $result_vs;
    }

    /**
     * Получаем информацию о выставленных счетах
     * @param $startDay
     * @param $endDay
     *
     * @return array
     */
    public function getInvoices($startDay, $endDay)
    {
        $data['report'] = array();

        $this->urfa->call(-0x4047);
        $this->urfa->put_int((int)$startDay);
        $this->urfa->put_int((int)$endDay);


        $this->urfa->send();

        $data['currency_id'] = $this->urfa->get_int();
        $data['currency_name'] = $this->urfa->get_string();

        $count = $this->urfa->get_int();

        $iter = 0;
        for ($i = 0; $i < $count; $i++) {
            $count2 = $this->urfa->get_int();
            for ($i2 = 0; $i2 < $count2; $i2++) {
                $tmp = array();
                $tmp['id'] = $this->urfa->get_int();
                $tmp['ext_num'] = $this->urfa->get_string();
                $tmp['invoice_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());

                if ($this->urfa->get_int()) {
                    $tmp['is_payed'] = 'да';
                } else {
                    $tmp['is_payed'] = 'нет';
                }
                $tmp['account_id'] = $this->urfa->get_int();
                $tmp['amount'] = $this->urfa->get_double();
                $tmp['total_tax'] = $this->urfa->get_double();
                $tmp['total_sum_plus_total_tax'] = $this->urfa->get_double();
                // $tmp['link'] = getInvoiceLink($tmp['id']);
                $data['report'][$iter++] = $tmp;
            }
        }
        $this->urfa->finish();

        return $data;
    }

    /**
     * Отчёт о блокировках
     * @param $startDay
     * @param $endDay
     *
     * @return array
     */
    public function getBlockingReport($startDay, $endDay)
    {
        $report = array();

        $this->urfa->call(-16403);
        $this->urfa->put_int((int)$startDay);
        $this->urfa->put_int((int)$endDay);

        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();
            $tmp['account_id'] = $this->urfa->get_int();
            $tmp['start_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['expire_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['what_blocked'] = Urfa_Resolve::resolveBlockItem($this->urfa->get_int());
            $tmp['block_type'] = Urfa_Resolve::resolveBlockState($this->urfa->get_int());
            $tmp['comment'] = $this->urfa->get_string();
            $report[$i] = $tmp;
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Отчёт по сессиям
     * @param $startDay
     * @param $endDay
     *
     * @return array
     */
    public function getDHSReport($startDay, $endDay)
    {
        $report = array();

        //$this->urfa->call(-16407);
        $this->urfa->call(-16464);
        $this->urfa->put_int((int)$startDay);
        $this->urfa->put_int((int)$endDay);


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $tmp = array();

            $tmp['id'] = $this->urfa->get_int();
            $tmp['account_id'] = $this->urfa->get_int();
            $tmp['slink_id'] = $this->urfa->get_int();
            $tmp['start_time'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $tmp['end_time'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            // $tmp['framed_ip'] = Urfa_Resolve::ip2string($this->urfa->get_int());
            $tmp['framed_ip'] = $this->urfa->get_ip_address()->toString();
            $tmp['framed_ip6'] = $this->urfa->get_ip_address()->toString();
            $tmp['nas_port'] = $this->urfa->get_int();
            $tmp['session_id'] = $this->urfa->get_string();
            $tmp['nas_port_type'] = $this->urfa->get_int();
            $tmp['username'] = $this->urfa->get_string();
            $tmp['service_type'] = Urfa_Resolve::resolveServiceType($this->urfa->get_int());
            $tmp['framed_protocol'] = $this->urfa->get_int();
            // $tmp['nas_ip'] = Urfa_Resolve::ip2string($this->urfa->get_int());
            $tmp['nas_ip'] = $this->urfa->get_ip_address()->toString();
            $tmp['nas_id'] = $this->urfa->get_string();
            $tmp['acct_status_type'] = $this->urfa->get_int();
            $tmp['input_pack'] = $this->urfa->get_long();
            $tmp['input_bytes'] = $this->urfa->get_long();
            $tmp['output_pack'] = $this->urfa->get_long();
            $tmp['output_bytes'] = $this->urfa->get_long();

            $tmp['time'] = $this->urfa->get_long();
            $tmp['calling_station_id'] = $this->urfa->get_string();
            $tmp['called_station_id'] = $this->urfa->get_string();

            $tmp['session_time'] = Urfa_Resolve::getTimeFromSec($tmp['time']);

            $cost = 0.0;

            $count2 = $this->urfa->get_int();

            for ($i2 = 0; $i2 < $count2; $i2++) {
                $trange_id = $this->urfa->get_int();
                $account_id = $this->urfa->get_int();
                $duretion = $this->urfa->get_long();
                $base_cost = $this->urfa->get_double();
                $cost += $this->urfa->get_double();
            }
            $tmp['total_cost'] = Urfa_Resolve::roundDouble($cost);

            $report[$i] = $tmp;
        }
        $this->urfa->finish();

        return $report;
    }

    public function getInvoiceDocument($sum)
    {
        $subst = array();

        $this->urfa->call(-0x4052);
        $this->urfa->send();
        $subst['@USR_ID@'] = $this->urfa->get_int();
        $subst['@USR_LOGIN@'] = $this->urfa->get_string();
        $subst['@USR_BASIC_ACOUNT@'] = $this->urfa->get_int();
        $this->urfa->get_double();
        $this->urfa->get_double();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $subst['@USR_FULL_NAME@'] = $this->urfa->get_string();
        $subst['@USR_JUR_ADDRESS@'] = $this->urfa->get_string();
        $subst['@USR_ACT_ADDRESS@'] = $this->urfa->get_string();
        $subst['@USR_WORK_TEL@'] = $this->urfa->get_string();
        $subst['@USR_HOME_TEL@'] = $this->urfa->get_string();
        $subst['@USR_MOB_TEL@'] = $this->urfa->get_string();
        $subst['@USR_WEB_PAGE@'] = $this->urfa->get_string();
        $subst['@USR_ICQ@'] = $this->urfa->get_string();
        $subst['@USR_TAX@'] = $this->urfa->get_string();
        $subst['@USR_KPP@'] = $this->urfa->get_string();
        $this->urfa->get_int();
        $subst['@USR_BANK_ACCOUNT@'] = $this->urfa->get_string();
        $this->urfa->get_int();
        $this->urfa->get_double();
        $subst['@USR_PASSPORT@'] = $this->urfa->get_string();
        $this->urfa->get_double();
        $subst['@USR_EMAIL@'] = $this->urfa->get_string();
        $this->urfa->finish();

        $this->urfa->call(-0x15032);
        $this->urfa->put_int(0);
        $this->urfa->send();
        $subst["@SUP_NAME@"] = $this->urfa->get_string();
        $subst["@SUP_NAME_SHORT@"] = $this->urfa->get_string();
        $subst["@SUP_FACT_ADDR@"] = $this->urfa->get_string();
        $subst["@SUP_JUR_ADDR@"] = $this->urfa->get_string();
        $subst["@SUP_INN@"] = $this->urfa->get_string();
        $subst["@SUP_KPP@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_NAME@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_CITY@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_BIC@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_PAYM_ACC@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_ACCOUNT@"] = $this->urfa->get_string();
        $subst["@SUP_HEADMAN@"] = $this->urfa->get_string();
        $subst["@SUP_BOOKEEPER@"] = $this->urfa->get_string();
        $subst["@SUP_HEADMAN_SHORT@"] = $this->urfa->get_string();
        $subst["@SUP_BOOKEEPER_SHORT@"] = $this->urfa->get_string();
        $subst["@SUM@"] = $sum;
        $this->urfa->finish();

        return $subst;
    }

    public function getInvoiceDoc($id, $sum = false)
    {
        $subst = array();
        $this->urfa->call(-0x4052);
        $this->urfa->send();
        $subst['@USR_ID@'] = $this->urfa->get_int();
        $subst['@USR_LOGIN@'] = $this->urfa->get_string();
        $subst['@USR_BASIC_ACOUNT@'] = $this->urfa->get_int();
        $this->urfa->get_double();
        $this->urfa->get_double();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $this->urfa->get_int();
        $jur = ($this->urfa->get_int() != 0);
        $subst['@USR_FULL_NAME@'] = $this->urfa->get_string();
        $subst['@USR_JUR_ADDRESS@'] = $this->urfa->get_string();
        $subst['@USR_ACT_ADDRESS@'] = $this->urfa->get_string();
        $subst['@USR_WORK_TEL@'] = $this->urfa->get_string();
        $subst['@USR_HOME_TEL@'] = $this->urfa->get_string();
        $subst['@USR_MOB_TEL@'] = $this->urfa->get_string();
        $subst['@USR_WEB_PAGE@'] = $this->urfa->get_string();
        $subst['@USR_ICQ@'] = $this->urfa->get_string();
        $subst['@USR_TAX@'] = $this->urfa->get_string();
        $subst['@USR_KPP@'] = $this->urfa->get_string();
        $this->urfa->get_int();
        $subst['@USR_BANK_ACCOUNT@'] = $this->urfa->get_string();
        $this->urfa->get_int();
        $this->urfa->get_double();
        $subst['@USR_PASSPORT@'] = $this->urfa->get_string();
        $this->urfa->get_double();
        $subst['@USR_EMAIL@'] = $this->urfa->get_string();
        $this->urfa->finish();

        $this->urfa->call(-0x15032);
        $this->urfa->put_int(0);
        $this->urfa->send();
        $subst["@SUP_NAME@"] = $this->urfa->get_string();
        $subst["@SUP_NAME_SHORT@"] = $this->urfa->get_string();
        $subst["@SUP_FACT_ADDR@"] = $this->urfa->get_string();
        $subst["@SUP_JUR_ADDR@"] = $this->urfa->get_string();
        $subst["@SUP_INN@"] = $this->urfa->get_string();
        $subst["@SUP_KPP@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_NAME@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_CITY@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_BIC@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_PAYM_ACC@"] = $this->urfa->get_string();
        $subst["@SUP_BANK_ACCOUNT@"] = $this->urfa->get_string();
        $subst["@SUP_HEADMAN@"] = $this->urfa->get_string();
        $subst["@SUP_BOOKEEPER@"] = $this->urfa->get_string();
        $subst["@SUP_HEADMAN_SHORT@"] = $this->urfa->get_string();
        $subst["@SUP_BOOKEEPER_SHORT@"] = $this->urfa->get_string();
        $subst["@SUM@"] = $sum;
        $this->urfa->finish();

        $this->urfa->call(-0x15033);
        $this->urfa->put_int($id);
        $this->urfa->send();
        $total = 0.0;
        $balance = 0.0;
        if ($this->urfa->get_int() != 0) {
            $subtotal = 0.0;
            $tax = 0.0;
            $subst["@INVC_NUM@"] = $id;
            $subst["@INVC_ACCOUNT_ID@"] = $this->urfa->get_int();
            $subst["@INVC_DATE@"] = date('d/m/Y', $this->urfa->get_int());
            $period_start = $this->urfa->get_int();
            $period_end = $this->urfa->get_int();
            if ($period_start != 0 && $period_end != 0) {
                $subst["@INVC_PERIOD_START@"] = date('d/m/Y', $period_start);
                $subst["@INVC_PERIOD_END@"] = date('d/m/Y', $period_end);
            } else {
                $subst["@INVC_PERIOD_START@"] = "-";
                $subst["@INVC_PERIOD_END@"] = "-";
            }
            $balance = $this->urfa->get_double();
            $subst["@INVC_BALANCE_ON_SET@"] = $balance;
            $rows = $this->urfa->get_int();
            $table_jur = "";
            $table_ind = "";
            for ($i = 0; $i < $rows; $i++) {
                $name = $this->urfa->get_string();
                $this->urfa->get_double();
                $this->urfa->get_double();
                $sum_cost = $this->urfa->get_double();
                $tax_amount = $this->urfa->get_double();
                $table_jur .= "<tr>\n" .
                    "<td align=\"center\">" . ($i + 1) . "</td>\n" .
                    "<td align=\"left\">" . htmlspecialchars($name) . "</td>\n" .
                    "<td align=\"center\">" . Urfa_Resolve::roundDouble($sum_cost) . "</td>\n" .
                    "<td align=\"center\">1</td>\n" .
                    "<td align=\"center\">шт</td>\n" .
                    "<td align=\"center\">" . Urfa_Resolve::roundDouble($sum_cost) . "</td>\n" .
                    "</tr>\n";
                $table_ind .= "<tr>\n" .
                    "<td align=\"left\">" . htmlspecialchars($name) . "</td>\n" .
                    "<td align=\"right\">" . Urfa_Resolve::roundDouble($sum_cost) . "</td>\n" .
                    "</tr>";
                $subtotal += $sum_cost;
                $tax += $tax_amount;
            }
            $total = $subtotal + $tax;
            $subst["@INVC_PRODUCT_TABLE@"] = $table_jur;
            $subst["@INDIVIDUAL_INVOICE_ROWS@"] = $table_ind;
            $subst["@INVC_ROW_COUNT@"] = $rows;
            $subst["@INVC_SUBTOTAL@"] = Urfa_Resolve::roundDouble($subtotal);
            $subst["@INVC_TAX@"] = Urfa_Resolve::roundDouble($tax);
            $subst["@INVC_TOTAL@"] = Urfa_Resolve::roundDouble($total);
        }
        $this->urfa->finish();

        $this->urfa->call(-0x15034);
        $this->urfa->put_double($total);
        $this->urfa->send();
        $subst["@INVC_TOTAL_STR@"] = $this->urfa->get_string();
        $this->urfa->finish();

        $sum_to_pay = $total - $balance;
        if ($sum_to_pay < 0.0) {
            $sum_to_pay = 0.0;
        }
        $subst["@INVC_SUBTOTAL_WITHOUT_BALANCE@"] = Urfa_Resolve::roundDouble($sum_to_pay);


        return array($subst, $jur);
    }


    /**
     * Оплата по карточке
     * @param int $account
     * @param int $card
     * @param string $pin
     *
     * @return array Возвращает массив, где ['state'] статус регистрации карточки.
     * В случае неудачи в ['message'] возвращается сообщение об ошибке
     */
    public function cardPayment($account, $card, $pin)
    {
        $this->urfa->call(-0x4045);
        $this->urfa->put_int((int)$account);
        $this->urfa->put_int((int)$card);
        $this->urfa->put_string($pin);

        $this->urfa->send();

        $result['state'] = $this->urfa->get_int();
        if ($result['state'] == 0) {
            $result['message'] = $this->urfa->get_string();
        }
        $this->urfa->finish();

        return $result;
    }


    public function userEdit($user, $data)
    {
        $this->urfa->call(-0x4040);


        $this->urfa->put_string($user['full_name']);
        $this->urfa->put_string($user['actual_address']);
        $this->urfa->put_string($user['juridical_address']);
        $this->urfa->put_string($user['work_telephone']);


        $this->urfa->put_string($data['home_telephone']);
        $this->urfa->put_string($data['mobile_telephone']);


        $this->urfa->put_string($user['web_page']);
        $this->urfa->put_string($user['icq']);
        $this->urfa->put_string($user['passport']);
        $this->urfa->put_int($user['bank_id']);
        $this->urfa->put_string($user['user_bank_account']);

        // var_dump($data['email']);
        $this->urfa->put_string($data['email']);


        $this->urfa->send();
        $this->urfa->finish();

        return true;
    }

    /**
     * Отчёт по телефонии
     * @param $startDay
     * @param $endDay
     *
     * @return array
     */
    public function getTelephonyReport($startDay, $endDay)
    {
        $report = array();
        $iter = 0;

        $this->urfa->call(-16537);
        $this->urfa->put_int((int)$startDay);
        $this->urfa->put_int((int)$endDay);


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $count2 = $this->urfa->get_int();
            for ($i2 = 0; $i2 < $count2; $i2++) {
                $tmp = array();
                $tmp['start_time'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
                $tmp['end_time'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
                $tmp['time'] = $this->urfa->get_int();
                $tmp['session_time'] = Urfa_Resolve::getTimeFromSec($tmp['time']);
                $tmp['setup_time'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
                $tmp['calling_station'] = $this->urfa->get_string();
                $tmp['called_station'] = $this->urfa->get_string();
                $tmp['direction'] = $this->urfa->get_string();
                $tmp['total_cost'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $report[$iter++] = $tmp;
            }
        }
        $this->urfa->finish();

        return $report;
    }

    /**
     * Возврат заблокированныx средств
     *
     * @param $account
     *
     * @since 5.2.1-009
     */
    public function repay($account)
    {

        $this->urfa->call(-0x15029);
        $this->urfa->put_int((int)$account);
        $this->urfa->send();
        $this->urfa->finish();
    }

    /**
     * Получение информации о турбо режиме
     * @param $slink_id
     * @param $settings_id
     *
     * @return array
     * @since 5.2.1-009
     */
    public function getTurboModeInfo($slink_id, $settings_id)
    {
        $data = array();
        $this->urfa->call(-0x1200c);
        $this->urfa->put_int($slink_id);
        $this->urfa->send();
        $modesCnt = $this->urfa->get_int();

        /*        $data['incoming_rate'] = $this->urfa->get_int();
                $data['outgoing_rate'] = $this->urfa->get_int();
                $data['duration'] = Urfa_Resolve::getTimeFromSec($this->urfa->get_int());
                $data['cost'] = $this->urfa->get_double();
                if ($data['incoming_rate'] == 0) {
                    $data['incoming_rate'] = $data['outgoing_rate'];
                }
                if ($data['outgoing_rate'] == 0) {
                    $data['outgoing_rate'] = $data['incoming_rate'];
                }
                $data['incoming_rate'] = Urfa_Resolve::resolveRate($data['incoming_rate']);
                $data['outgoing_rate'] = Urfa_Resolve::resolveRate($data['outgoing_rate']);
                $this->urfa->finish();*/


        for ($m = 0; $m < $modesCnt; $m++) {

            $data = array();
            $data['id'] = $this->urfa->get_int();
            $data['name'] = $this->urfa->get_string();
            $data['incoming_rate'] = $this->urfa->get_int();
            $data['outgoing_rate'] = $this->urfa->get_int();

            if ($data['incoming_rate'] == 0) {
                $data['incoming_rate'] = Urfa_Resolve::resolveLimit($data['outgoing_rate'], 1024);
            }

            if ($data['outgoing_rate'] == 0) {
                $data['outgoing_rate'] = Urfa_Resolve::resolveLimit($data['incoming_rate'], 1024);
            }

            $isDuration = $this->urfa->get_int();
            if ($isDuration) {
                $data['duration'] = $this->urfa->get_int();
            } else {
                $data['incoming_limit'] = Urfa_Resolve::resolveLimit($this->urfa->get_long(), 1024);
                $data['outgoing_limit'] = Urfa_Resolve::resolveLimit($this->urfa->get_long(), 1024);
            }

            $data['cost'] = $this->urfa->get_double();

            if ($data['id'] == $settings_id) {
                $settings = $data;
            }
        }
        $this->urfa->finish();
        return $settings;

    }

    /**
     * Установка турбо режима
     * @param $slink_id
     *
     * @return bool
     *
     * @since 5.2.1-009
     */
    public function setTurboMode($slink_id, $settings_id)
    {
        $this->urfa->call(-0x1200d);
        $this->urfa->put_int((int)$slink_id);
        $this->urfa->put_int((int)$settings_id);
        $this->urfa->send();
        $data = $this->urfa->get_int();
        $this->urfa->finish();
        return $data;
    }

    /**
     * Получаем информацию о прочих списаниях
     * @param $startDay
     * @param $endDay
     *
     * @return array
     */
    public function getOtherChargesReport($startDay, $endDay)
    {
        $report = array();

        $this->urfa->call(-0x15027);
        $this->urfa->put_int((int)$startDay);
        $this->urfa->put_int((int)$endDay);


        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {
            $count2 = $this->urfa->get_int();
            for ($j = 0; $j < $count2; $j++) {
                $tmp = array();
                $tmp['account_id'] = $this->urfa->get_int();
                $tmp['login'] = $this->urfa->get_string();
                $tmp['full_name'] = $this->urfa->get_string();
                $tmp['date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
                $tmp['amount'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
                $tmp['service_type'] = Urfa_Resolve::resolveServiceType($this->urfa->get_int());
                $report[] = $tmp;
            }
        }
        $this->urfa->finish();

        return $report;
    }

    public function getBurntPayment()
    {
        $bp = false;
        $this->urfa->call(-16425);
        $this->urfa->send();
        $is_exist = $this->urfa->get_int();
        if ($is_exist > 0) {
            $bp['first_payment_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $bp['last_payment_date'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $bp['time_to_burn'] = Urfa_Resolve::getDateFromTimestamp($this->urfa->get_int());
            $bp['amount'] = Urfa_Resolve::roundDouble($this->urfa->get_double());
            $bp['discounted'] = $this->urfa->get_double();
        }
        $this->urfa->finish();

        return $bp;
    }

    public function getTurboMode()
    {

        define("SLINK_SHAPING_TURBO_MODE_AVAILABLE", 2);

        $all_slinks = array();

        $this->urfa->call(-0x402f);
        $this->urfa->send();
        $count = $this->urfa->get_int();
        for ($i = 0; $i < $count; $i++) {

            $all_slinks[$i] = array();

            $all_slinks[$i]['id'] = $this->urfa->get_int();
            $this->urfa->get_int();//service_id
            $this->urfa->get_int();//service_type
            $all_slinks[$i]['name'] = $this->urfa->get_string();
            $this->urfa->get_string();//tariff_name
            $this->urfa->get_int();//discount_period_start
            $this->urfa->get_int();//discount_period_end
            $this->urfa->get_double();//cost
            $this->urfa->get_double();//discounted_in_curr_period
        }
        $this->urfa->finish();

        $slinks = array();
        $slinks_cnt = 0;

        for ($i = 0, $size = count($all_slinks); $i < $size; $i++) {
            $slink_id = $all_slinks[$i]['id'];

            $this->urfa->call(-0x404a);
            $this->urfa->put_int($slink_id);
            $this->urfa->send();
            $service_type = $this->urfa->get_int();
            $this->urfa->finish();

            if ($service_type != 3) // not iptraffic service
            {
                continue;
            }

            $this->urfa->call(-0x12010);
            $this->urfa->put_int($slink_id);
            $this->urfa->send();

            $res = $this->urfa->get_int();
            if ($res != 0) {
                $this->urfa->finish();
                continue;
            }

            $flags = $this->urfa->get_int();
            $this->urfa->get_int();//incoming_rate
            $this->urfa->get_int();//outgoing_rate
            $turbo_mode_start = $this->urfa->get_int();
            $this->urfa->finish();

            if (($flags & SLINK_SHAPING_TURBO_MODE_AVAILABLE) != 0) {
                $slinks[$slinks_cnt] = $all_slinks[$i];
                $slinks[$slinks_cnt]["active"] = $turbo_mode_start > 0;
                $slinks_cnt++;
            }
            /* if ($service_type == 3) {
                 $this->urfa->call(-0x12009);
                 $this->urfa->put_int($slink_id);
                 $this->urfa->send();

                 $flags = $this->urfa->get_int();
                 $this->urfa->get_int();//incoming_rate
                 $this->urfa->get_int();//outgoing_rate
                 $turbo_mode_start = $this->urfa->get_int();

                 $this->urfa->finish();
                 if (($flags & SLINK_SHAPING_TURBO_MODE_AVAILABLE) != 0) {
                     $slinks[$slinks_cnt] = $all_slinks[$i];
                     $slinks[$slinks_cnt]['active'] = $turbo_mode_start > 0;
                     $slinks_cnt++;
                 }
             }*/
        }

        return $slinks;
    }
}