<?php

class usdt_plugin
{

    public static $info = [
        'name'     => 'usdt',
        'showname' => 'USDT 收款插件',
        'author'   => '莫名',
        'link'     => 'https://qzone.work/codes/741.html',
        'types'    => ['usdt'],
        'inputs'   => [
            'appid'  => [
                'name' => 'USDT-TRC20 收款地址',
                'type' => 'input',
                'note' => '确保地址正确，收款错误无法追回',
            ],
            'appkey' => [
                'name' => '交易汇率（CNY）',
                'type' => 'input',
                'note' => '如果填AUTO则实时获取市场汇率，推荐填AUTO；举例：6.3',
            ],
            'appurl' => [
                'name' => '超时时长（秒）',
                'type' => 'input',
                'note' => '建议20分钟；填：1200',
            ],
        ],
        'select'   => null,
        'note'     => '',
    ];

    public static function submit()
    {
        global $channel, $order, $conf, $DB, $cdnpublic;
    
        // --- 保留你的原始参数 ---
        $valid   = (strtotime($order['addtime']) + intval($channel['appurl'])) * 1000;
        $address = $channel['appid'];
        $rate    = self::getRate();
        $usdt    = round($order['realmoney'] / $rate, 2);
    
        // 关键修复：用当前时间窗口
        $addtime = date('Y-m-d H:i:s', time() - intval($channel['appurl']));
        $params = [$channel['id'], 0, $addtime, $order['trade_no'], $order['money']];
    
        try {
            // ==============================
            // 1. 开启事务
            // ==============================
            $DB->exec("BEGIN");
    
            // ==============================
            // 2. 核心：带行级锁的查询 (FOR UPDATE)
            // 只有加上 FOR UPDATE，多线程下才会强制排队
            // ==============================
            $row = $DB->getRow(
                "SELECT param 
                 FROM pre_order 
                 WHERE channel = ? 
                 AND status = ? 
                 AND addtime >= ? 
                 AND trade_no != ? 
                 AND money = ? 
                 AND param IS NOT NULL 
                 AND param > 0 
                 ORDER BY CAST(param AS DECIMAL(18,2)) DESC 
                 LIMIT 1 
                 FOR UPDATE", 
                $params
            );
    
            // ==============================
            // 3. 计算新金额
            // ==============================
            if ($row && isset($row['param']) && $row['param'] > 0) {
                $usdt = bcadd((string)$row['param'], '0.01', 2);
            }
    
            // ==============================
            // 4. 执行更新
            // ==============================
            $DB->exec('UPDATE pre_order SET param = ? WHERE trade_no = ?', [$usdt, $order['trade_no']]);
    
            // ==============================
            // 5. 提交事务（释放锁）
            // ==============================
            $DB->exec("COMMIT");
    
        } catch (\Exception $e) {
            // 如果中间报错，回滚操作，防止数据混乱
            $DB->exec("ROLLBACK");
            throw $e;
        }
    
        // --- 后续逻辑保持不变 ---
        ob_clean();
        header("Content-Type: text/html; charset=UTF-8");
    
        define('PLUGIN_PATH', PLUGIN_ROOT . PAY_PLUGIN . '/');
        define('PLUGIN_STATIC', 'https://epay-usdt.pages.dev');
    
        require_once PLUGIN_PATH . '/pay.php';
    
        exit(0);
    }


    public static function getRate(): float
    {
        global $channel;

        if (isset($channel['appkey']) && $channel['appkey'] > 0) {

            return floatval($channel['appkey']);
        }

        $api    = 'https://api.coinmarketcap.com/data-api/v3/cryptocurrency/detail/chart?id=825&range=1H&convertId=2787';
        $resp   = get_curl($api);
        $data   = json_decode($resp, true);
        $points = $data['data']['points'];
        $point  = array_pop($points);

        return floatval($point['c'][0]);
    }

    public static function cron(array $channel)
    {
        global $DB;

        $list    = self::getTransferInList($channel['appid'], 24);
        $addtime = date('Y-m-d H:i:s', time() - intval($channel['appurl']));
        $rows    = $DB->query('select * from pre_order where channel = ? and status = ? and addtime >= ?', [$channel['id'], 0, $addtime]);
        while ($order = $rows->fetch(PDO::FETCH_ASSOC)) {
            foreach ($list as $item) {
                if ($item['money'] == $order['param'] && $item['time'] >= strtotime($order['addtime'])) {

                    processNotify($order, $item['trade_id'], $item['buyer']);
                    echo sprintf("订单回调成功：%s\n", $order['trade_no']);
                }
            }
        }

        echo "---[监控执行结束： " . date('Y-m-d H:i:s') . "]---\n";
    }

    public static function getTransferInList(string $address, int $hour = 3): array
    {
        $result = [];
        $end    = time() * 1000;
        $start  = strtotime("-$hour hour") * 1000;
        $params = [
            'limit'           => 300,
            'start'           => 0,
            'direction'       => 'in',
            'relatedAddress'  => $address,
            'start_timestamp' => $start,
            'end_timestamp'   => $end,
        ];
        $api    = "https://apilist.tronscan.org/api/token_trc20/transfers?" . http_build_query($params);
        $resp   = get_curl($api);
        $data   = json_decode($resp, true);

        if (empty($data)) {

            return $result;
        }

        foreach ($data['token_transfers'] as $transfer) {
            if ($transfer['to_address'] == $address && $transfer['finalResult'] == 'SUCCESS') {
                $result[] = [
                    'time'     => $transfer['block_ts'] / 1000,
                    'money'    => $transfer['quant'] / 1000000,
                    'trade_id' => $transfer['transaction_id'],
                    'buyer'    => $transfer['from_address'],
                ];
            }
        }

        return $result;
    }
}
