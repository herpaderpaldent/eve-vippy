<?php
namespace admin\view;

class Payments
{
    function getOverview($arguments=[])
    {
        if (!\User::getUSER()->getIsSysAdmin())
            \AppRoot::redirect("");

        $payments = \admin\model\Payment::findAll(["approved" => 0, "deleted" => 0], ["transactiondate asc"]);
        \AppRoot::title(count($payments)." Pending Payments");

        $tpl = \SmartyTools::getSmarty();
        $tpl->assign("payments", $payments);
        return $tpl->fetch("admin/payments/overview");
    }

    function getEdit($arguments=[])
    {
        if (!\User::getUSER()->getIsSysAdmin())
            \AppRoot::redirect("");

        $payment = new \admin\model\Payment(array_shift($arguments));

        $tpl = \SmartyTools::getSmarty();
        $tpl->assign("payment", $payment);
        $tpl->assign("authgroups", \admin\model\AuthGroup::getAuthGroups());
        return $tpl->fetch("admin/payments/edit");
    }

    function getApprove($arguments=[])
    {
        if (!\User::getUSER()->getIsSysAdmin())
            \AppRoot::redirect("");

        $payment = new \admin\model\Payment(array_shift($arguments));
        $payment->approved = true;
        $payment->store();
        \AppRoot::redirect("admin/payments");
    }

    function getDelete($arguments=[])
    {
        if (!\User::getUSER()->getIsSysAdmin())
            \AppRoot::redirect("");

        $payment = new \admin\model\Payment(array_shift($arguments));
        $payment->delete();
        \AppRoot::redirect("admin/payments");
    }
}