<?php

class usdt_plugin
{

    public static $info = [
        'name'     => 'usdt',
        'showname' => 'USDT 收款插件',
        'author'   => '莫名修改版',
        'link'     => 'https://github.com/mmfdsfsd/epay_usdt',
        'types'    => ['usdt'],
        'inputs'   => [
            'appid' => [
                'name' => 'USDT-TRC20 收款地址',
                'type' => 'input',
                'note' => '确保地址正确，收款错误无法追回',
            ],
            'appkey' => [
                'name' => '交易汇率（CNY）',
                'type' => 'input',
                'note' => 'AUTO实时获取汇率',
            ],
            'appurl' => [
                'name' => '超时时长（秒）',
                'type' => 'input',
                'note' => '例如1200',
            ],
        ],
        'select' => null,
        'note' => '',
    ];


    /**
     * 创建支付订单
     */
    public static function submit()
    {
        global $channel, $order, $DB, $cdnpublic;


        $valid = (
            strtotime($order['addtime'])
            + intval($channel['appurl'])
        ) * 1000;


        $address = $channel['appid'];


        // 获取汇率
        $rate = self::getRate();


        // 计算USDT
        $usdt = round(
            $order['realmoney'] / $rate,
            2
        );


        /*
         * 防止金额重复
         * 使用事务 + 行锁
         */
        try {

            $DB->exec("BEGIN");


            // 当前有效订单时间窗口
            $addtime = date(
                'Y-m-d H:i:s',
                time() - intval($channel['appurl'])
            );


            $row = $DB->getRow(
                "
                SELECT param
                FROM pay_order
                WHERE channel = ?
                AND status = 0
                AND addtime >= ?
                AND trade_no != ?
                AND money = ?
                AND param IS NOT NULL
                AND param > 0
                ORDER BY 
                CAST(param AS DECIMAL(18,2)) DESC
                LIMIT 1
                FOR UPDATE
                ",
                [
                    $channel['id'],
                    $addtime,
                    $order['trade_no'],
                    $order['money']
                ]
            );


            if (
                $row
                && isset($row['param'])
                && $row['param'] > 0
            ) {

                $usdt = bcadd(
                    (string)$row['param'],
                    '0.01',
                    2
                );
            }


            // 保存USDT金额
            $DB->exec(
                "
                UPDATE pay_order
                SET param = ?
                WHERE trade_no = ?
                ",
                [
                    $usdt,
                    $order['trade_no']
                ]
            );


            $DB->exec("COMMIT");


        } catch (\Exception $e) {

            $DB->exec("ROLLBACK");

            throw $e;
        }



        ob_clean();

        header(
            "Content-Type:text/html;charset=UTF-8"
        );


        define(
            'PLUGIN_PATH',
            PLUGIN_ROOT . PAY_PLUGIN . '/'
        );


        define(
            'PLUGIN_STATIC',
            'https://epay-usdt.pages.dev'
        );


        require_once PLUGIN_PATH.'/pay.php';


        exit;
    }



    /**
     * 获取USDT汇率
     */
    public static function getRate(): float
    {

        global $channel;


        if (
            isset($channel['appkey'])
            &&
            $channel['appkey'] > 0
        ) {

            return floatval(
                $channel['appkey']
            );
        }


        $api =
        'https://api.coinmarketcap.com/data-api/v3/cryptocurrency/detail/chart?id=825&range=1H&convertId=2787';


        $resp = get_curl($api);


        $data=json_decode(
            $resp,
            true
        );


        $points=$data['data']['points'];


        $point=array_pop($points);


        return floatval(
            $point['c'][0]
        );

    }



    /**
     * 定时扫描TRON
     */
    public static function cron(array $channel)
    {
    
        global $DB;
    
    
        $list = self::getTransferInList(
            $channel['appid'],
            6
        );
        
        /**
         *   调试专用
         *   print_r($list);
        */
    
        $addtime = date(
            'Y-m-d H:i:s',
            time() - intval($channel['appurl'])
        );
    
    
        echo "扫描最近".intval($channel['appurl'])."秒内订单\n";
        echo "起始时间：".$addtime."\n";
    
    
        $rows=$DB->query(
            "
            SELECT *
            FROM pay_order
            WHERE channel=?
            AND status=0
            AND api_trade_no IS NULL
            AND addtime>=?
            ",
            [
                $channel['id'],
                $addtime
            ]
        );
    
    
        $count=0;
    
    
        while(
            $order=$rows->fetch(PDO::FETCH_ASSOC)
        ){
            
            if(
                time() >
                strtotime($order['addtime'])
                +
                intval($channel['appurl'])
            ){
        
                echo "订单已过期\n";
                continue;
        
            }

                
            $count++;
    
            echo "进入订单循环\n";
            
            /**
            *   调试专用
            *   print_r($order);
            **/
    
            foreach($list as $item){
    
    
                echo "订单:"
                .$order['trade_no']
                ."\n";
    
    
                echo "订单金额:"
                .$order['param']
                ."\n";
    
    
                echo "链上金额:"
                .$item['money']
                ."\n";
    
    
                echo "订单时间:"
                .strtotime($order['addtime'])
                ."\n";
    
    
                echo "链上时间:"
                .$item['time']
                ."\n";
    
    
                echo "金额比较:"
                .bccomp(
                    (string)$item['money'],
                    (string)$order['param'],
                    2
                )
                ."\n";
    
    
                echo "时间比较:"
                .(
                    $item['time'] >= strtotime($order['addtime'])
                    ? 'YES'
                    :'NO'
                )
                ."\n\n";
    
    
                if(
                    bccomp(
                        (string)$item['money'],
                        (string)$order['param'],
                        2
                    ) == 0
    
                    &&
    
                    $item['time'] >= strtotime($order['addtime'])
                ){
                    // 防止TRON交易重复使用
                    $used = $DB->getRow(
                        "
                        SELECT trade_no
                        FROM pay_order
                        WHERE api_trade_no=?
                        AND trade_no!=?
                        LIMIT 1
                        ",
                        [
                            $item['trade_id'],
                            $order['trade_no']
                        ]
                    );
                
                
                    if($used){
                
                        echo "交易已经匹配订单："
                        .$used['trade_no']
                        ."\n";
                
                        continue;
                
                    }
                    
                    // 标记交易
                    $result = $DB->exec(
                        "
                        UPDATE pay_order
                        SET 
                        api_trade_no=?,
                        buyer=?
                        WHERE trade_no=?
                        AND status=0
                        AND api_trade_no IS NULL
                        ",
                        [
                            $item['trade_id'],
                            $item['buyer'],
                            $order['trade_no']
                        ]
                    );
                    
                    
                    if($result != 1){
                    
                        echo "订单已被其他进程处理："
                        .$order['trade_no']
                        ."\n";
                    
                        continue;
                    
                    }


                    //重新读取订单
                    $order=$DB->getRow(
                        "
                        SELECT *
                        FROM pay_order
                        WHERE trade_no=?
                        LIMIT 1
                        ",
                        [
                            $order['trade_no']
                        ]
                    );
                    
                    
                    if(
                        !$order
                        ||
                        $order['status'] != 0
                    ){
                    
                        echo "订单状态异常，跳过\n";
                    
                        continue;
                    
                    }
                    
                    
                    try {

                         $result = processNotify(
                            $order,
                            $item['trade_id'],
                            $item['buyer']
                        );
                    
                    
                        if($result === false){
                    
                            throw new Exception(
                                '订单回调返回失败'
                            );
                    
                        }
                    
                    } catch(Exception $e){
                    
                        echo "回调失败："
                        .$e->getMessage()
                        ."\n";
                    
                        //释放交易锁
                        $DB->exec(
                            "
                            UPDATE pay_order
                            SET 
                            api_trade_no=NULL,
                            buyer=NULL
                            WHERE trade_no=?
                            AND status=0
                            ",
                            [
                                $order['trade_no']
                            ]
                        );
                    
                    
                        continue;
                    
                    }
                  
    
                    echo sprintf(
                        "订单回调成功：%s\n",
                        $order['trade_no']
                    );
    
    
                    break;
    
                }
    
    
            }
    
        }
    
    
        echo "订单数量：".$count."\n";
    
    
        echo "---[监控执行结束："
        .date('Y-m-d H:i:s')
        ."]---\n";
    
    }



    /**
     * 获取TRC20转账
     */
    public static function getTransferInList(
        string $address,
        int $hour=3
    ):array
    {
    
        $result=[];

        $start=0;
        
        $limit=50;
        
        $startTimestamp = strtotime("-$hour hour") * 1000;
        
        $endTimestamp = time() * 1000;
        
        
        while(true)
        {
        
            $params=[
        
                'limit'=>$limit,
        
                'start'=>$start,
        
                'direction'=>'in',
        
                'relatedAddress'=>$address,
        
                'start_timestamp'=>$startTimestamp,
        
                'end_timestamp'=>$endTimestamp,
        
            ];

    
            $api=
            "https://apilist.tronscan.org/api/token_trc20/transfers?"
            .
            http_build_query($params);
    
    
    
            $resp=get_curl($api);
    
    
            $data=json_decode(
                $resp,
                true
            );
    
    
            if(
                !is_array($data)
                ||
                empty($data['token_transfers'])
            )
            {
                break;
            }
    
    
    
            $count=0;
    
    
            foreach(
                $data['token_transfers']
                as $transfer
            ){
    
                if(
                    $transfer['to_address']
                    ==
                    $address
    
                    &&
    
                    $transfer['finalResult']
                    ==
                    'SUCCESS'
                ){
    
                    $result[]=[
    
                        'time'=>
                        $transfer['block_ts']/1000,
    
    
                        'money'=>
                        round(
                            $transfer['quant']/1000000,
                            2
                        ),
    
    
                        'trade_id'=>
                        $transfer['transaction_id'],
    
    
                        'buyer'=>
                        $transfer['from_address'],
    
                    ];
    
                }
    
    
                $count++;
    
            }
    
    
    
            // 不满50条说明已经最后一页
    
            if(
                $count < $limit
            ){
    
                break;
    
            }
    
    
            // 下一页
    
            $start += $limit;
    
    
    
            // 防止异常死循环
    
            if(
                $start > 1000
            ){
    
                break;
    
            }
    
   
        }
        usort(
            $result,
            function($a,$b){
                return $a['time'] <=> $b['time'];
            }
        );
        return $result;
    
    }

}
