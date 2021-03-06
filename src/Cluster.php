<?php
namespace ClickHouseDB;

class Cluster
{


    /**
     * @var array
     */
    private $nodes = [];


    /**
     * @var Client[]
     */
    private $clients = [];

    /**
     * @var Client
     */
    private $defaultClient;

    /**
     * @var array
     */
    private $badNodes = [];

    /**
     * @var string
     */
    private $error = "";
    /**
     * @var array
     */
    private $resultScan = [];
    /**
     * @var bool
     */
    private $defaultHostName;

    /**
     * @var int
     */
    private $scanTimeOut=10;

    /**
     * @var array
     */
    private $tables = [];

    /**
     * @var array
     */
    private $hostsnames = [];
    /**
     * @var bool
     */
    private $isScaned = false;

    /**
     * @var bool
     */
    private $replicasIsOk;

    /**
     * Cluster constructor.
     * @param $connect_params
     * @param array $settings
     * @param int $scanTimeOut
     */
    public function __construct($connect_params, $settings = [])
    {
        $this->defaultClient = new Client($connect_params, $settings);
        $this->defaultHostName = $this->defaultClient->getConnectHost();
        $this->setNodes(gethostbynamel($this->defaultHostName));
    }

    /**
     * @return Client
     */
    private function defaultClient()
    {
        return $this->defaultClient;
    }

    /**
     * @param $scanTimeOut
     */
    public function setScanTimeOut($scanTimeOut)
    {
        $this->scanTimeOut = $scanTimeOut;
    }

    /**
     * @param $nodes
     */
    public function setNodes($nodes)
    {
        $this->nodes = $nodes;
    }

    /**
     * @return array
     */
    public function getNodes()
    {
        return $this->nodes;
    }

    /**
     * @return array
     */
    public function getBadNodes()
    {
        return $this->badNodes;
    }


    /**
     * Connect all nodes and scan
     *
     * @return $this
     */
    public function connect()
    {
        if (!$this->isScaned) {
            $this->rescan();
        }
        return $this;
    }

    /**
     * Проверяете состояние кластера, запрос взят из документации к CH
     * total_replicas<2 - не подходит для без репликационных кластеров
     *
     *
     * @param $replicas
     * @return bool
     */
    private function isReplicasWork($replicas)
    {
        $ok = true;
        if (!is_array($replicas)) {
            // @todo нет массива ошибка, т/к мы работем с репликами?
            // @todo Как быть есть в кластере НЕТ реплик ?
            return false;
        }
        foreach ($replicas as $replica) {
            if ($replica['is_readonly']) {
                $ok = false;
                $this->error[] = 'is_readonly : ' . json_encode($replica);
            }
            if ($replica['is_session_expired']) {
                $ok = false;
                $this->error[] = 'is_session_expired : ' . json_encode($replica);
            }
            if ($replica['future_parts'] > 20) {
                $ok = false;
                $this->error[] = 'future_parts : ' . json_encode($replica);
            }
            if ($replica['parts_to_check'] > 10) {
                $ok = false;
                $this->error[] = 'parts_to_check : ' . json_encode($replica);
            }

            // @todo : rewrite total_replicas=1 если кластер без реплики , нужно проверять какой класте и сколько в нем реплик
//            if ($replica['total_replicas']<2) {$ok=false;$this->error[]='total_replicas : '.json_encode($replica);}


            if ($replica['active_replicas'] < $replica['total_replicas']) {
                $ok = false;
                $this->error[] = 'active_replicas : ' . json_encode($replica);
            }
            if ($replica['queue_size'] > 20) {
                $ok = false;
                $this->error[] = 'queue_size : ' . json_encode($replica);
            }
            if (($replica['log_max_index'] - $replica['log_pointer']) > 10) {
                $ok = false;
                $this->error[] = 'log_max_index : ' . json_encode($replica);
            }
            if (!$ok) break;
        }
        return $ok;
    }

    /**
     * @return $this
     */
    public function rescan()
    {
        $this->error = ["R"];
        /*
         * 1) Получаем список IP
         * 2) К каждому подключаемся по IP, через activeClient подменяя host на ip
         * 3) Достаем информацию system.clusters + system.replicas c каждой машины , overwrite { DnsCache + timeOuts }
         * 4) Определяем нужные машины для кластера/реплики
         * 5) .... ?
         */
        $statementsReplicas = [];
        $statementsClusters = [];
        $result = [];

        $badNodes = [];
        $replicasIsOk = true;

        foreach ($this->nodes as $node) {
            $this->defaultClient()->setHost($node);
            $statementsReplicas[$node] = $this->defaultClient()->selectAsync('SELECT * FROM system.replicas');
            $statementsClusters[$node] = $this->defaultClient()->selectAsync('SELECT * FROM system.clusters');
            // пересетапим timeout
            $statementsReplicas[$node]->getRequest()->setDnsCache(0)->timeOut($this->scanTimeOut)->connectTimeOut($this->scanTimeOut);
            $statementsClusters[$node]->getRequest()->setDnsCache(0)->timeOut($this->scanTimeOut)->connectTimeOut($this->scanTimeOut);
        }
        $this->defaultClient()->executeAsync();


        foreach ($this->nodes as $node) {
            try {
                $r = $statementsReplicas[$node]->rows();
                foreach ($r as $row) {
                    $tables[$row['database']][$row['table']][$node] = $row['replica_path'];
                }
                $result['replicas'][$node] = $r;
            } catch (\Exception $E) {
                $result['replicas'][$node] = false;
                $badNodes[$node] = $E->getMessage();
                $this->error[] = 'statementsReplicas:' . $E->getMessage();
            }
            // ---------------------------------------------------------------------------------------------------
            $hosts=[];
            $tables=[];
            try {
                $c = $statementsClusters[$node]->rows();
                $result['clusters'][$node] = $c;
                foreach ($c as $row) {
                    $hosts[$row['host_address']][$row['port']] = $row['host_name'];
                    $result['cluster.list'][$row['cluster']][$row['host_address']] =
                        [
                            'shard_weight' => $row['shard_weight'],
                            'replica_num' => $row['replica_num'],
                            'shard_num' => $row['shard_num'],
                            'is_local' => $row['is_local']
                        ];
                }

            } catch (\Exception $E) {
                $result['clusters'][$node] = false;

                $this->error[] = 'clusters:' . $E->getMessage();
                $badNodes[$node] = $E->getMessage();

            }
            $this->hostsnames = $hosts;
            $this->tables = $tables;
            // ---------------------------------------------------------------------------------------------------
            // Проверим что репликации хорошо идут
            $rIsOk = $this->isReplicasWork($result['replicas'][$node]);
            $result['replicasIsOk'][$node] = $rIsOk;
            if (!$rIsOk) $replicasIsOk = false;
            // ---------------------------------------------------------------------------------------------------
        }
        // badNodes = array(6) {  '222.222.222.44' =>  string(13) "HttpCode:0 ; " , '222.222.222.11' =>  string(13) "HttpCode:0 ; "
        $this->badNodes = $badNodes;

        // Востановим DNS имя хоста в клиенте
        $this->defaultClient()->setHost($this->defaultHostName);


        $this->isScaned = true;
        $this->replicasIsOk = $replicasIsOk;
        $this->error[] = "Bad replicasIsOk, in " . json_encode($result['replicasIsOk']);
        // ------------------------------------------------
        // @todo Уточнить на боевых падениях и при разношорсных конфигурациях...
        if (sizeof($this->badNodes)) {
            $this->error[] = 'Have bad node : ' . json_encode($this->badNodes);
            $this->replicasIsOk = false;
        }
        $this->error = false;
        $this->resultScan = $result;
        // @todo Мы подключаемся ко всем в списке DNS, нужно пререить что запросы вернули все хосты к которым мы подключались
        return $this;
    }

    /**
     * @return boolean
     */
    public function isReplicasIsOk()
    {
        return $this->connect()->replicasIsOk;
    }

    /**
     * @return Client
     */
    public function client($node)
    {
        // Создаем клиенты под каждый IP
        if (empty($this->clients[$node])) {
            $this->clients[$node] = clone $this->defaultClient();
        }

        $this->clients[$node]->setHost($node);

        return $this->clients[$node];
    }

    /**
     * @return Client
     */
    public function clientLike($cluster,$ip_addr_like)
    {
        $nodes=$this->getClusterNodes($cluster);
        $list_ips_need=explode(';',$ip_addr_like);
        $find=false;
        foreach($list_ips_need as $like)
        {
            foreach ($nodes as $node)
            {
                if (stripos($node,$like)!==false)
                {
                    $find=$node;
                }
                if ($find) break;
            }
            if ($find) break;
        }
        if (!$find){
            $find=$nodes[0];
        }
        return $this->client($find);
    }
    /**
     * @return Client
     */
    public function activeClient()
    {
        return $this->client($this->nodes[0]);
    }

    /**
     * @param $cluster
     * @return int
     */
    public function getClusterCountShard($cluster)
    {
        $table = $this->getClusterInfoTable($cluster);
        $c = [];
        foreach ($table as $row) {
            $c[$row['shard_num']] = 1;
        }
        return sizeof($c);
    }

    /**
     * @param $cluster
     * @return int
     */
    public function getClusterCountReplica($cluster)
    {
        $table = $this->getClusterInfoTable($cluster);
        $c = [];
        foreach ($table as $row) {
            $c[$row['replica_num']] = 1;
        }
        return sizeof($c);
    }

    /**
     * @param $cluster
     * @return mixed
     */
    public function getClusterInfoTable($cluster)
    {
        $this->connect();
        if (empty($this->resultScan['cluster.list'][$cluster])) throw new QueryException('Cluster not find:' . $cluster);
        return $this->resultScan['cluster.list'][$cluster];
    }

    /**
     * @param $cluster
     * @return array
     */
    public function getClusterNodes($cluster)
    {
        return array_keys($this->getClusterInfoTable($cluster));
    }

    /**
     * @return array
     */
    public function getClusterList()
    {
        $this->connect();
        return array_keys($this->resultScan['cluster.list']);
    }

    /**
     * Find nodes by : db_name.table_name
     *
     * @param $database_table
     * @return array
     */
    public function getNodesByTable($database_table)
    {
        $this->connect();
        list($db, $table) = explode('.', $database_table);

        if (empty($this->tables[$db][$table])) {
            throw new QueryException('Not find :' . $database_table);
        }
        return array_keys($this->tables[$db][$table]);
    }

    /**
     * Error string
     *
     * @return string
     */
    public function getError()
    {
        if (is_array($this->error)) {
            return implode(" ; " . $this->error);
        }
        return $this->error;
    }

}