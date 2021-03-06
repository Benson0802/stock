<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use App\Models\TransactionInfo;
use App\Models\TransactionTotalInfo;
use App\Models\StockInfo;
use Illuminate\Http\Client\RequestException;

class CrawlerController extends Controller
{
    public function index()
    {
        return self::stockOHLCV(20210907);
        return self::crawlerTotal();
        return self::crawler();
    }

    public static function crawler()
    {
        try{
            $url = "https://fubon-ebrokerdj.fbs.com.tw/Z/ZG/ZGB/ZGB.djhtm";
            $response = Http::get($url);
            $body = $response->body();
            $file = mb_convert_encoding($body, "utf-8", "big5");
            $date = self::findMid("資料日期：","</div></td></tr>",$file);
            $date = date('Y-m-d',strtotime($date));
            $count = TransactionInfo::where('date',$date)->count();
            if($count > 0)
            {
                return '今日資料已寫入!';
            }
            sleep(3);
            $company = TransactionInfo::COMPANY;
            $sub_company = TransactionInfo::getSubCompany();
            sleep(3);
            foreach($company as $key=>$val)
            {
                foreach($sub_company[$key] as $sub_key=>$sub_val)
                {
                    //$url = "https://fubon-ebrokerdj.fbs.com.tw/z/zg/zgb/zgb0.djhtm?a=".$key."&b=".$sub_key;
                    $url = "http://210.66.194.151/z/zg/zgb/zgb0.djhtm?a=".$key."&b=".$sub_key;
                    $response = Http::get($url);
                    $body = $response->body();

                    $file = mb_convert_encoding($body, "utf-8", "big5");
                    $all = preg_split("/\tGenLink2stk/i", $file);
                    unset($all[0]);
                    $final_all = [];

                    $url = null;
                    $response = null;
                    $body = null;

                    foreach ($all as $info) {
                        $info = preg_split("/\r\n/i", $info);
                        // dd($info);
                        $data_count = 0; //計算第幾個資料,0:買進金額 1:賣出金額 2:差額
                        foreach ($info as $str) {
                            $find = ["('", "');"]; //個股
                            $replace = ["", ""];
                            $stock_name = str_replace($find, $replace, $str);
                            $stock_name = explode("','", $stock_name);
                            if (isset($stock_name[0]) && isset($stock_name[1])) {
                                $data['code'] = substr($stock_name[0], 0, 2) == 'AS' ? substr($stock_name[0], 2, 6) : $stock_name[0]; //代碼
                                $data['name'] = $stock_name[1]; //中文
                                continue;
                            }
                            $find = self::findMid("nowrap>", "</td>", $str);

                            $find2 = self::findMid(';">', "</a>", $str);
                            if ($find2 !== false) //例外狀況
                            {
                                $final_all[] = $data; //先把第一組放進去
                                $find2 = (preg_split('/(?<!\p{Latin})(?=\p{Latin})|(?<!\p{Han})(?=\p{Han})|(?<![0-9])(?=[0-9])/u', $find2, -1, PREG_SPLIT_NO_EMPTY)); // Test 中文數字 1000 切割測試
                                $data['code'] = $find2[0]; //代碼
                                $data['name'] = $find2[1]; //中文
                                $data_count = 0;
                                continue;
                            }

                            if ($find === false) {
                                continue;
                            }

                            if ($data_count == 0) {
                                $data['data1'] = str_replace(',','',$find);
                            }
                            if ($data_count == 1) {
                                $data['data2'] = str_replace(',','',$find);
                            }
                            if ($data_count == 2) {
                                $data['data3'] = str_replace(',','',$find);
                            }
                            $data_count++;
                        }

                        $db = new TransactionInfo();
                        $db->company_code = $key;
                        $db->company_name = $val;
                        $db->sub_company_code = $sub_key;
                        $db->sub_company_name = $sub_val;
                        $db->stock_code = $data['code'];
                        $db->stock_name = $data['name'];
                        $db->date = $date;
                        $db->type = $data['data3'] > 0?'buy':'sell';
                        $db->buy = $data['data1'];
                        $db->sell = $data['data2'];
                        $db->diff = $data['data3'];
                        $db->save();
                    }
                    sleep(5);
                }
            }
        }
        catch (RequestException $ex)
        {
            abort(500, $ex->getMessage());
        }
    }
    public static function stockOHLCV($date)
    {
        try{
            $count = StockInfo::where('date',$date)->count();
            if($count > 0)
            {
                return $date.'日資料已寫入!';
            }

            $url = "https://www.twse.com.tw/exchangeReport/MI_INDEX?response=html&date=".$date."&type=ALLBUT0999";
            $response = Http::get($url);
            $body = $response->body();
            $all = preg_split("/<table>/i", $body);
            
            $url = null;
            $response = null;
            $body = null;
            $all_data = [];
            $col = ['stock_code','stock_name','vol2','vol','price','open','high','low','close','type','diff'];
            //證券代號	證券名稱	成交股數	成交筆數	成交金額	開盤價	最高價	最低價	收盤價	漲跌(+/-)	漲跌價差	最後揭示買價	最後揭示買量	最後揭示賣價	最後揭示賣量	本益比
            foreach ($all as $info) {
                $find = self::findMid("每日收盤行情","不含權證、牛熊證",$info);

                if(!$find){
                    continue;
                }
                $info = preg_split("/<tbody>/i", $info);
                $info = preg_split("/<\/tr>/i", $info[1]);
                $all_data = [];
                foreach ($info as $i=> $str) {
                    $stock_info = preg_split("/d>\n/i", $str);
                    
                    $type = 1;//預設漲
                    foreach ($stock_info as $i => $info) {
                        if(@$col[$i] == null) continue;
                        $new_info = self::findMid("<td>","</t",$info);
                        $new_info = str_replace(',','',$new_info);
                        if($col[$i] == 'type'){
                            $new_info = self::findMid(">","</p>",$new_info);
                            $type = $new_info == '+' ? 1 : -1;
                        }
                        if($col[$i] == 'diff'){
                            $new_info = $new_info * $type;
                        }
                        $data[$col[$i]] = $new_info == '--' ? 0 : $new_info;
                        
                    }
                    if($data['stock_code'] == '') continue;
                    $data['date'] = $date;
                    $all_data[] = $data;
                }
            }
            if(count($all_data) > 0){
                StockInfo::insert($all_data);
                sleep(10);
                return $date.'寫入成功';
            }else{
                sleep(5);
                return $date.'今日無資料';
            }
        }
        catch (RequestException $ex)
        {
            abort(500, $ex->getMessage());
        }
    }
    public static function crawlerTotal()
    {
        try{

            $company = TransactionInfo::COMPANY;
            $url = "https://fubon-ebrokerdj.fbs.com.tw/Z/ZG/ZGB/ZGB.djhtm";
            $response = Http::get($url);
            $body = $response->body();
            $file = mb_convert_encoding($body, "utf-8", "big5");
            $date = self::findMid("資料日期：","</div></td></tr>",$file);
            $date = date('Y-m-d',strtotime($date));
            $count = TransactionTotalInfo::where('date',$date)->count();
            if($count > 0)
            {
                return '今日資料已寫入!';
            }

            $all = preg_split("/zgb0.djhtm/i", $file);
            unset($all[0]);

            $url = null;
            $response = null;
            $body = null;
            $all_data = [];
            foreach ($all as $info) {
                $info = preg_split("/\r\n/i", $info);
                foreach ($info as $i=> $str) {
                    if($i == 0){
                        $data['date'] = $date;
                        $data['company_code'] = self::findMid("?a=","&b=",$str);
                        $data['company_name'] = $company[$data['company_code']] ?? '';
                        $data['sub_company_code'] = self::findMid("&b=",'">',$str);
                        $data['sub_company_name'] = self::findMid('">',"</a></td>",$str);
                        continue;
                    }
                    if($i == 1){
                        $find = self::findMid("nowrap>", "</td>", $str);
                        $data['buy'] = str_replace(',','',$find);
                        continue;
                    }
                    if($i == 2){
                        $find = self::findMid("nowrap>", "</td>", $str);
                        $data['sell'] = str_replace(',','',$find);
                        continue;
                    }
                    if($i == 3){
                        $find = self::findMid("nowrap>", "</td>", $str);
                        $data['diff'] = str_replace(',','',$find);
                        $data['type'] = $data['diff'] > 0?'buy':'sell';
                        continue;
                    }

                }
                $all_data[] = $data;
            }
            TransactionTotalInfo::insert($all_data);
            return '寫入成功';
        }
        catch (RequestException $ex)
        {
            abort(500, $ex->getMessage());
        }
    }
    /**
     * 給頭尾找中間的值
     */
    public static function findMid($head, $foot, $str)
    {
        $head_pos = strpos($str, $head);
        if ($head_pos === false) {
            return false;
        }
        $foot_pos = strpos($str, $foot);
        if ($foot_pos === false) {
            return false;
        }
        $h_len = strlen($head);
        $head_pos += $h_len;
        return substr($str, $head_pos, $foot_pos - $head_pos);
    }
}
