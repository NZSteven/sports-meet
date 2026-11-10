<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_can_manage():bool {return current_user_can('manage_options')||current_user_can('sports_meet_competition_admin');}
function race_install_head_referee():void {
    $caps=['read'=>true,'sports_meet_judge'=>true,'sports_meet_plugin_access'=>true,'sports_meet_competition_admin'=>true];
    $role=get_role('sports_meet_head_referee');
    if(!$role){add_role('sports_meet_head_referee','运动会裁判长',$caps);$role=get_role('sports_meet_head_referee');}
    if($role)foreach($caps as $cap=>$allowed)if(!$role->has_cap($cap))$role->add_cap($cap);
    $admin=get_role('administrator');if($admin&&!$admin->has_cap('sports_meet_plugin_access'))$admin->add_cap('sports_meet_plugin_access');
}
add_action('init',__NAMESPACE__.'\\race_install_head_referee',5);
