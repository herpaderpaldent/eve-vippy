<?php
namespace system\console;

class Maintenance
{
    function doDefault($arguments=[])
    {
        \AppRoot::doCliOutput("do maintenance");
        \AppRoot::setMaxExecTime(0);
        foreach (\Modules::getModuleObjects() as $module) {
            if (method_exists($module, "doMaintenance"))
                $module->doMaintenance();
        }
        return "Done";
    }
}