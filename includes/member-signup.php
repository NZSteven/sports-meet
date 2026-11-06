<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function athlete_account_fields(int $user=0): string {
    $id=$user?(int)q('SELECT athlete_id FROM athlete_accounts WHERE user_id=?',[$user])->fetchColumn():0;
    $rows=race_roster_rows();
    if($id){
        foreach($rows as $athlete){
            if((int)$athlete['id']!==$id)continue;
            return '<p>报名身份：'.esc_html($athlete['team_name'].' · '.$athlete['name']).'</p>'
                .'<input type="hidden" name="school_id" value="'.(int)$athlete['team_id'].'"><input type="hidden" name="athlete_id" value="'.$id.'">';
        }
    }
    $html='<label>所属高校<select name="school_id" required><option value="">请选择高校</option>';
    foreach(all('teams') as $team)$html.='<option value="'.(int)$team['id'].'">'.esc_html($team['name']).'</option>';
    $html.='</select></label><label>运动员姓名<select name="athlete_id" required><option value="">请选择本人姓名</option>';
    foreach($rows as $athlete){
        $label=$athlete['name'].'（'.$athlete['team_name'].' · '.race_category($athlete).'）';
        $html.='<option value="'.(int)$athlete['id'].'" data-school="'.(int)$athlete['team_id'].'">'.esc_html($label).'</option>';
    }
    return $html.'</select></label>';
}

function bind_athlete_account(int $athlete,int $user): void {
    $bound=q('SELECT athlete_id,user_id FROM athlete_accounts WHERE athlete_id=? OR user_id=?',[$athlete,$user])->fetchAll();
    foreach($bound as $row){
        if((int)$row['athlete_id']===$athlete && (int)$row['user_id']===$user)return;
        throw new \RuntimeException('该报名或账号已关联其他身份，请联系组委会。');
    }
    q('INSERT INTO athlete_accounts(athlete_id,user_id) VALUES(?,?)',[$athlete,$user]);
}

add_action('delete_user',function(int $user){
    if(get_option('sports_meet_community_ready'))q('DELETE FROM athlete_accounts WHERE user_id=?',[$user]);
});

function athlete_signup_form(): string {
    return '<details id="sports-meet-signup"><summary>注册参赛者账号</summary>'
        .'<form class="sm-disclaimer-form" method="post" action="'.esc_url(admin_url('admin-post.php','relative')).'">'
        .'<input type="hidden" name="action" value="sports_meet_athlete_signup">'
        .wp_nonce_field('sports_meet_athlete_signup','sm_nonce',false,false).athlete_account_fields()
        .'<label>邮箱<input type="email" name="email" autocomplete="email" required></label>'
        .'<label>密码<input type="password" name="password" minlength="10" maxlength="128" autocomplete="new-password" required></label>'
        .'<label><input type="checkbox" name="confirm_identity" value="1" required> 本人确认所选高校和姓名是我的报名资料</label>'
        .'<input type="hidden" name="statement_hash" value="'.hash('sha256',disclaimer_text()).'">'
        .'<label><input type="checkbox" name="agree" value="1" required> 本人同意以上免责声明</label>'
        .'<button type="submit">注册并提交同意</button></form></details>';
}

add_action('admin_post_nopriv_sports_meet_athlete_signup',function(){
    check_admin_referer('sports_meet_athlete_signup','sm_nonce');
    $data=wp_unslash($_POST);
    foreach(['athlete_id','school_id','email','password','confirm_identity'] as $field){
        if(!is_string($data[$field]??null))wp_die('请填写完整的注册资料。','',['response'=>400,'back_link'=>true]);
    }
    $email=sanitize_email($data['email']);
    $password=$data['password'];
    $text=disclaimer_text();
    $hash=hash('sha256',$text);
    if($text===''||!get_option('sports_meet_disclaimer_ready')||($data['statement_hash']??'')!==$hash||($data['agree']??'')!=='1'){
        wp_die('请重新打开免责声明页面，阅读并勾选同意后提交。','',['response'=>400,'back_link'=>true]);
    }
    if(!ctype_digit($data['athlete_id'])||!ctype_digit($data['school_id'])||!is_email($email)
        ||strlen($password)<10||strlen($password)>128||$data['confirm_identity']!=='1'){
        wp_die('请确认本人身份，输入有效邮箱及至少 10 个字符的密码。','',['response'=>400,'back_link'=>true]);
    }
    try{
        $user=race_transaction(function() use($data,$email,$password,$hash){
            $athlete=q('SELECT id,name,team_id FROM athletes WHERE id=? AND team_id=?',[(int)$data['athlete_id'],(int)$data['school_id']])->fetch();
            if(!$athlete)throw new \RuntimeException('所选高校和姓名不匹配。');
            if(email_exists($email))throw new \RuntimeException('该邮箱已有账号，请登录。');
            if(q('SELECT user_id FROM athlete_accounts WHERE athlete_id=?',[(int)$athlete['id']])->fetchColumn()){
                throw new \RuntimeException('该报名已有账号，请登录或联系组委会。');
            }
            $user=wp_insert_user(['user_login'=>'runner_'.bin2hex(random_bytes(9)), 'user_email'=>$email,
                'user_pass'=>$password,'display_name'=>$athlete['name'],'role'=>'subscriber']);
            if(is_wp_error($user))throw new \RuntimeException($user->get_error_message());
            bind_athlete_account((int)$athlete['id'],(int)$user);
            record_disclaimer_acceptance($athlete,$hash,(int)$user);
            return (int)$user;
        });
    }catch(\Throwable $error){wp_die(esc_html($error->getMessage()),'',['response'=>400,'back_link'=>true]);}
    wp_set_current_user($user);
    wp_set_auth_cookie($user);
    wp_safe_redirect(page_url('disclaimer'));exit;
});
