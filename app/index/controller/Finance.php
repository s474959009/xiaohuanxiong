<?php


namespace app\index\controller;

use app\model\Chapter;
use app\model\ChargeCode;
use app\common\RedisHelper;
use app\model\User;
use app\model\UserBuy;
use app\model\UserFinance;
use app\model\VipCode;
use app\pay\Pay;
use app\service\FinanceService;
use app\service\PromotionService;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;
use app\model\UserOrder;

class Finance extends BaseUc
{
    protected $financeModel;
    protected $pay;
    protected $balance;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->financeModel = app('financeModel');
        $this->pay = app('payService');
        $this->balance = $this->financeModel->getBalance($this->uid);
    }

    //用户钱包
    public function wallet()
    {
        $charge_sum = $this->financeModel->getChargeSum($this->uid);
        $spending_sum = $this->financeModel->getSpendingSum($this->uid);
        View::assign([
            'balance' => $charge_sum - $spending_sum,
            'charge_sum' => $charge_sum,
            'spending_sum' => $spending_sum
        ]);
        return view($this->tpl);
    }

    //充值记录
    public function chargehistory()
    {

        View::assign([
            'balance' => $this->balance,
        ]);
        return view($this->tpl);
    }

    public function spendinghistory()
    {
        View::assign([
            'balance' => $this->balance,
        ]);
        return view($this->tpl);
    }

    public function buyhistory()
    {
        return view($this->tpl);
    }

    //处理充值
    public function charge()
    {
        if (request()->isPost()) {
            $data = request()->param();
            $money = $data['money'];  //用户充值金额
            $pay_type = $data['pay_type']; //是充值金币还是购买vip
            $pay_code = $data['code'];
            $order = new UserOrder();
            $order->order_id = time() . gen_uid(10);
            $order->user_id = $this->uid;
            $order->money = $money;
            $order->status = 0; //未完成订单
            $order->pay_type = $pay_type;
            $order->expire_time = time() + 86400; //订单失效时间往后推一天
            $res = $order->save();
            if ($res) {
                $r = $this->pay->submit($order->order_id, $money, $pay_type, $pay_code); //调用功能类，进行充值处理
                if ($r['type'] == 'html') {
                    $template = new \think\Template();
                    $template->display($r['content']);
                } else {
                    $this->redirect($r['content']);
                }
            } else {
                $this->error('订单创建失败');
            }
        } else {
            View::assign([
                'balance' => $this->balance,
                'moneys' => config('payment.money'),
                'payments' => config('payment.pay.channel'),
            ]);
            return view($this->tpl);
        }
    }

    public function Kami()
    {
        if (request()->isPost()) {
            $str_code = trim(input('code'));
            try {
                $code = ChargeCode::where('code', '=', $str_code)->findOrFail();
                if ((int)$code->used == 3) {
                    return json(['err' => 1, 'msg' => '该充值码已经被使用']);
                }

                $code->used = 3; //变更状态为使用
                $code->update_time = time();
                $res = $code->save();
                if ($res) {
                    $order = new UserOrder();
                    $order->user_id = $this->uid;
                    $order->money = $code->money;
                    $order->status = 1; //完成订单
                    $order->pay_type = 1;
                    $order->summary = $str_code; //备注卡密
                    $order->expire_time = time() + 86400; //订单失效时间往后推一天
                    $order->save();

                    $userFinance = new UserFinance();
                    $userFinance->user_id = $this->uid;
                    $userFinance->money = $code->money; //充值卡面额
                    $userFinance->usage = 1; //用户充值
                    $userFinance->summary = '卡密充值';
                    $userFinance->save(); //存储用户充值数据

                    $promotionService = new PromotionService();
                    $promotionService->rewards($this->uid, $code->money); //调用推广处理函数
                    return json(['err' => 0, 'msg' => '充值码使用成功']);
                } else {
                    return json(['err' => 1, 'msg' => '充值码使用失败']);
                }
            } catch (DataNotFoundException $e) {
                return json(['err' => 1, 'msg' => '该充值码不存在']);
            } catch (ModelNotFoundException $e) {
                return json(['err' => 1, 'msg' => '该充值码不存在']);
            }
        }
        $url = config('payment.kami.url');
        View::assign([
            'url' => $url,
        ]);
        return view($this->tpl);
    }

    //用户支付回跳网址
    public function feedback()
    {

        View::assign([
            'balance' => $this->balance,
            'header_title' => '支付成功'
        ]);
        return view($this->tpl);
    }

    public function buychapter()
    {
        $id = input('chapter_id');
        $chapter = Chapter::with(['photos' => function ($query) {
            $query->order('pic_order');
        }, 'book'])->cache('chapter:' . $id, 600, 'redis')->find($id);
        if (request()->isPost()) {
            $result = $this->financeModel->buyChapter($chapter, $this->uid);
            return json($result);
        }

        View::assign([
            'balance' => $this->balance,
            'chapter' => $chapter,
            'price' => $chapter->book->money
        ]);
        return view($this->tpl);
    }

    //vip会员页面
    public function vip()
    {
        try {
            $user = User::findOrFail($this->uid);
            if (request()->isPost()) {
                $arr = config('payment.vip'); //拿到vip配置数组
                $money = (int)request()->param('money'); //拿到用户选择的vip
                $this->balance = $this->financeModel->getBalance($this->uid); //这里不查询缓存，直接查数据库更准确
                foreach ($arr as $key => $value) {
                    if ((int)$value['price'] == (int)$money) {
                        $pay_type = 2; //充值渠道 vip
                        $pay_code = request()->post('code');
                        $order = new UserOrder();
                        $order->user_id = $this->uid;
                        $order->money = $money;
                        $order->status = 0; //未完成订单
                        $order->pay_type = $pay_type;
                        $order->expire_time = time() + 86400; //订单失效时间往后推一天
                        $res = $order->save();
                        if ($res) {
                            $number = config('site.domain').'_';
                            $r = $this->pay->submit($number . $order->id, $money, $pay_type, $pay_code); //调用功能类，进行充值处理
                            if ($r['type'] == 'html') {
                                $template = new \think\Template();
                                $template->display($r['content']);
                            } else {
                                $this->redirect($r['content']);
                            }
                        } else {
                            $this->error('订单创建失败');
                        }
                    }
                }
            } else {
                $time = $user->vip_expire_time - time();
                $day = 0;
                if ($time > 0) {
                    $day = ceil(($user->vip_expire_time - time()) / (60 * 60 * 24));
                }
                View::assign([
                    'balance' => $this->balance,
                    'user' => $user,
                    'day' => $day,
                    'vips' => config('payment.vip'),
                    'payments' => config('payment.pay.channel'),
                ]);
                return view($this->tpl);
            }
        } catch (DataNotFoundException $e) {
            abort(404, '用户不存在');
        } catch (ModelNotFoundException $e) {
            abort(404, '用户不存在');
        }
    }

    public function vipexchange()
    {
        if ($this->request->isPost()) {
            $str_code = trim(input('code'));
            try {
                $user = User::findOrFail($this->uid);
                $code = VipCode::where('code', '=', $str_code)->findOrFail();
                if ((int)$code->used == 3) {
                    return json(['err' => 1, 'msg' => '该vip码已经被使用']);
                }

                Db::startTrans();
                Db::table($this->prefix . 'vip_code')->update([
                    'used' => 3, //变更状态为使用
                    'id' => $code->id,
                    'update_time' => time()
                ]);

                $vip_expire_time = (int)$user->vip_expire_time;
                if ($vip_expire_time < time()) { //说明vip已经过期
                    $new_expire_time = strtotime('+' . (int)$code->add_day . ' days', time());
                } else { //vip没过期，则在现有vip时间上增加
                    $new_expire_time = strtotime('+' . (int)$code->add_day . ' days', $vip_expire_time);
                }

                Db::table($this->prefix . 'user')->update([
                    'vip_expire_time' => $new_expire_time,
                    'id' => $this->uid
                ]);
                // 提交事务
                Db::commit();
                session('vip_expire_time', $new_expire_time);

                return json(['err' => 0, 'msg' => 'vip码使用成功']);
            } catch (DataNotFoundException $e) {
                abort(404, $e->getMessage());
            } catch (ModelNotFoundException $e) {
                abort(404, $e->getMessage());
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return json(['err' => 1, 'msg' => $e->getMessage()]);
            }
        }
        return view($this->tpl);
    }
}