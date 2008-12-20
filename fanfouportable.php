<?php
/*
Plugin Name: FanfouPortable
Plugin URI: http://jeeker.net/projects/fanfouportable/
Description: A simple tool about Fanfou,create new status when you publish a new post,and get the recent statuses from Fanfou.
Author: JinnLynn
Version: 0.2
Author URI: http://jeeker.net/
*/

define('FANFOUPORTABLE_VERSION','0.2');

require_once(ABSPATH . WPINC . '/class-snoopy.php');

class FanfouPortable {
    private $Snoop;
    private $DefaultOptions = array( 'username'         => '',
                                     'password'         => '',
                                     'notify_enable'    => 1,
                                     'notify_format'    => 'New Post: %Post_Title% %Post_URL%',
                                     'update_interval'  => 1800,
                                     'last_update_time' => 0,
                                     'last_update_hash' => '',
                                     'last_user_hash'   => '',
                                     'widget_title'     => 'Fanfou',                     //@since 0.2
                                     'widget_limit'     => 5 );                          //@since 0.2
    private $Options;

    /**
     * 构造函数，获取配置、处理POST动作等等
     * 
     * @since 0.1
     */
    function __construct() {

        $this->ParseOptions();
        $this->UpdateSchedule();
        $this->InitWidget();
        
        if ( $_POST['ffp_action']=='login_test') {
            $this->Options['username'] = trim(stripslashes($_POST['username']));
            $this->Options['password'] = trim(stripslashes($_POST['password']));
            if ($this->Login()) {
                exit('User successfully authenticated, Please update options before create new fanfou status.');
            } else {
                exit('Login failed. Please check your user name and password and try again...');
            }
        } else if ($_POST['ffp_action'] == 'send_message') {
            $content = trim($_POST['message']);
            if($this->Post($content)) {
                $this->Options['last_update_time'] = time() - $this->Options['update_interval'] - 5;
                $this->UpdateSchedule();
                exit('Send successful!');
            } else {
                exit('Send failed.Please check and try late again...');
            }
        }

        //register_activation_hook(__FILE__, array(&$this, 'Activate'));
        add_action('publish_post', array(&$this, 'NotifySchedule'));
        add_action('admin_menu', array(&$this, 'AdminMenu'));
        add_action('shutdown', array(&$this, 'SaveOptions'));

        add_action('fanfouportable_notify_schedule', array(&$this, 'Notify'));
        add_action('fanfouportable_update_schedule', array(&$this, 'Update'));

        if (is_admin()) 
            wp_enqueue_script('jquery');
        
    }

    /**
     * 保存配置
     *
     * @since 0.1
     */
    function SaveOptions() {
        foreach ($this->Options as $option_key => $option_value) {
            if (!array_key_exists($option_key, $this->DefaultOptions))
                unset($this->Options[$option_key]);
        }
        update_option('fanfouportable_options', $this->Options);
    }

    /**
     * 获取配置
     *
     * @since 0.1
     */
    function ParseOptions() {
        $old_options = get_option('fanfouportable_options');
        if (is_array($old_options)) {
            $this->Options = array_merge($this->DefaultOptions, $old_options);
        } else {
            $this->Options = $this->DefaultOptions;
        }
    }
    
    /**
     * 更新配置
     *
     * @since 0.1
     */
    function UpdateOptions() {
        $post_options = $_POST;
        foreach ( $post_options as $key => $value ) {
            if (!array_key_exists( $key, $this->DefaultOptions)) {
                unset($post_options[$key]);
                continue;
            }
            if (is_int($this->DefaultOptions[$key])) {
                $post_options[$key] = (int)$post_options[$key];
                if ($post_options[$key] < 0) 
                    $post_options[$key] = $this->DefaultOptions[$key];
            } else {
                $post_options[$key] = stripslashes(trim($post_options[$key]));
            }            
        }
        $this->Options = array_merge($this->Options, $post_options);
        $this->Options['last_update_time'] = time() - $this->Options['update_interval'];
        if($this->Options['last_user_hash'] != md5($this->Options['username']))
            $this->ResetCache();
    }

    /**
     * 初始化Snoopy
     *
     * @since 0.1
     */
    function InitSnoopy() {
        $this->Snoop = NULL;
        $this->Snoop = new Snoopy();
        $this->Snoop->agent = 'FanfouPortable - http://jeeker.net/';
        $this->Snoop->rawheaders = array('X-Twitter-Client'         => 'FanfouPortable',
                                         'X-Twitter-Client-Version' => FANFOUPORTABLE_VERSION,
                                         'X-Twitter-Client-URL'     => 'http://jeeker.net/projects/fanfouportable/');
        $this->Snoop->read_timeout = 1;
        $this->Snoop->user = $this->Options['username'];
        $this->Snoop->pass = $this->Options['password'];
    }

    /**
     * 登录Fanfou
     *
     * @since 0.1
     * @return bool
     */
    function Login() {
        $this->InitSnoopy();
        $this->Snoop->fetch('http://api.fanfou.com/statuses/user_timeline.xml');
        return (boolean) strpos($this->Snoop->response_code, '200');
    }

    /**
     * 更新计划
     *
     * @since 0.1
     */
    function UpdateSchedule() {
        if (($this->Options['last_update_time'] + $this->Options['update_interval']) < time()) {
            wp_clear_scheduled_hook('fanfouportable_update_schedule');
            wp_schedule_single_event(time() - 1, 'fanfouportable_update_schedule');
        }
    }

    /**
     * 更新，获取Fanfou上的最新状态
     *
     * @since 0.1
     */
    function Update() {
        if (empty($this->Options['username']))
            return $this->ResetCache();
        $this->InitSnoopy();
        $this->Snoop->fetch('http://api.fanfou.com/statuses/user_timeline.xml?id=' . $this->Options['username'] . '&hash=' . rand());
        if (!strpos($this->Snoop->response_code, '200')) {
            if($this->Options['last_user_hash'] != md5($this->Options['username']))
                $this->ResetCache();
            return;
        }
        $this->Options['last_update_time'] = time();
        $hash = md5($this->Snoop->results);
        if ($hash == $this->Options['last_update_hash']) 
            return;
        $this->Options['last_update_hash'] = $hash;
        $cache = $this->ParseXMLResults($this->Snoop->results);
        update_option('fanfouportable_cache', $cache);
    }
    
    /**
     * 解析XML，需要SimpleXML支持
     *
     * @since 0.1
     * @param string $xml_str
     * @return null | array
     */
    function ParseXMLResults($xml_str) {
        $xml = @simplexml_load_string($xml_str);
        if (!is_object($xml))
           return;
        $cache = array('user'     => array('screen_name' => (string)$xml->status[0]->user->screen_name,
                                           'id'          => (string)$xml->status[0]->user->id,
                                           'name'        => (string)$xml->status[0]->user->name,
                                           'location'    => (string)$xml->status[0]->user->location,
                                           'description' => (string)$xml->status[0]->user->description,
                                           'avatar'      => (string)$xml->status[0]->user->profile_image_url,
                                           'url'         => (string)$xml->status[0]->user->url),
                       'statuses' => array() ); 
        foreach ($xml->status as $status) {
            $timestamp = strtotime($status->created_at);
            $cache['statuses'][$timestamp] = array('id'   => (string)$status->id,
                                                   'text' => (string)$status->text);
        }
        return $cache;
    }
    
    /**
     * 重置缓存
     *
     * @since 0.1
     */
    function ResetCache() {
        $this->Options['last_update_hash'] = '';
        update_option('fanfouportable_cache', '');
        $this->Options['last_user_hash'] = md5($this->Options['username']);
    }

    /**
     * 日志发布通告计划
     *
     * @since 0.1
     * @param int $post_id
     */
    function NotifySchedule($post_id = 0) {
        if ($this->Options['notify_enable'] == 1) {
            $args = array($post_id);
            wp_schedule_single_event(time(), 'fanfouportable_notify_schedule', $args);
        }
    }

    /**
     * 日志发布通告
     *
     * @since 0.1
     * @param int $post_id
     */
    function Notify($post_id = 0) {
        if (!$this->Options['notify_enable'] 
            || get_post_meta($post_id, '_ffp_notified', true) == '1'
            || get_post_meta($post_id, 'fanfou_marker', true) == '1')                   //兼容FanfouTool
            return;
        $post = get_post($post_id);
        if (empty($post))
            return;
        $post_title = $post->post_title;
        $post_permalink = get_permalink($post_id);
        $blog_name = get_bloginfo('name');
        $blog_url = get_bloginfo('siteurl');

        $content = $this->Options['notify_format'];
        $before = array('%Post_Title%', '%Post_URL%',    '%Blog_Name%', '%Blog_URL%');
        $after  = array($post_title,    $post_permalink, $blog_name,    $blog_url);
        $content = str_replace($before, $after, $content);
        
        if (!$this->Post($content))
            return;
        if (!add_post_meta($post_id, '_ffp_notified', '1', true))
            update_post_meta($post_id, '_ffp_notified', '1');
        $this->Update();
    }

    /**
     * 往Fanfou上发布信息
     *
     * @since 0.1
     * @param string $text
     * @return bool
     */
    function Post($text = '') {
        if (empty($this->Options['username']) || empty($this->Options['password']) || empty($text))
            return false;
        $this->InitSnoopy();
        $this->Snoop->submit('http://api.fanfou.com/statuses/update.xml',
                             array('status' => $text,
                                   'source' => 'FanfouPortable'));
        if (strpos($this->Snoop->response_code, '200'))
            return true;
        return false;
    }

    /**
     * 获取Fanfou上策最新状态
     *
     * @since 0.1
     * @param string $args 参数
     * @return null | string
     */
    function GetPost($args='') {
        $defaults = array('limit'      => 10,
                          'noposttext' => 'None.',
                          'before'     => '<li>',
                          'after'      => '</li>',
                          'dateformat' => 'Y-m-d H:i:s',
                          'echo'       => 1);
        $args = wp_parse_args($args, $defaults);
        if (intval($args['limit']) < 1) {
            $args['limit'] = 1;
        } else if (intval($args['limit']) > 20)
            $args['limit'] = 20;
        $output = "\n<!-- Generated by FanfouPortable v" . FANFOUPORTABLE_VERSION . " - http://jeeker.net/jeeker/projects/fanfouportable/ -->\n";
        $cache = get_option('fanfouportable_cache');
        $statuses = $cache['statuses'];
        if(empty($statuses)) {
            $output .= $args['noposttext'];
        } else {
            $count = 0;
            foreach ($statuses as $timetable => $status) {
                $output .= "\t" . $args['before'];
                $output .= '<a href="http://fanfou.com/statuses/' . $status['id'] . '" title="Post at ' . $this->OutTime($timetable,$args['dateformat']) . '">'. $status['text'] . '</a>';
                $output .= $args['after'] . "\n";
                $count++;
                if ($count >= $args['limit'])
                    break;
            }
        }
        if ($args['echo'] == 1)
            echo $output;
        return $output;
    }

    /**
     * 格式化输出时间
     *
     * @since 0.1
     * @param int $timestamp
     * @param string $dateformat
     * @return string
     */
    function OutTime($timestamp, $dateformat) {
        $GMTOffset = get_option('gmt_offset');
        $LocalTime = $timestamp + 3600 * $GMTOffset;
        return date($dateformat, $LocalTime);
    }
    
    /**
     * 初始化Widget
     *
     * @since 0.2
     */
    function InitWidget() {
        $widget_ops = array('classname' => 'widget_fanfouportable', 'description' => __( "Fanfou最新状态") );
        wp_register_sidebar_widget('fanfouportable', __('FanfouPortable'), array($this, 'ShowWidget'), $widget_ops);
        wp_register_widget_control('fanfouportable', __('FanfouPortable'), array($this, 'ControlWidget'));
    }
    
    /**
     * Widget配置
     *
     * @since 0.2
     */
    function ControlWidget() {
        if($_POST['fanfouportable_widget_submit'] == 1) {
            $this->Options['widget_title'] = strip_tags(stripslashes($_POST['fanfouportable_widget_title']));
            $this->Options['widget_limit'] = intval(strip_tags(stripslashes($_POST['fanfouportable_widget_limit'])));
            if ($this->Options['widget_limit'] > 20) $this->Options['widget_limit'] = 20;
            if ($this->Options['widget_limit'] < 0)$this->Options['widget_limit'] = 1;
        }
        $widget_title = $this->Options['widget_title'];
        $widget_limit = $this->Options['widget_limit'];
        echo '
            <p><label for="fanfouportable_widget_title">Title: <input class="widefat" style="width:200px;" id="fanfouportable_widget_title" name="fanfouportable_widget_title" type="text" value="' . $widget_title . '" /></label></p>
            <p><label for="fanfouportable_widget_limit">Number: <input class="widefat" style="width:35px;" id="fanfouportable_widget_limit" name="fanfouportable_widget_limit" type="text" value="' . $widget_limit . '" /></label></p>
            <input type="hidden" id="fanfouportable_widget_submit" name="fanfouportable_widget_submit" value="1" />
        ';
    }
    
    /**
     * 显示Widget
     *
     * @since 0.2
     * @param array $widget_args
     * @param int $number
     */
    function ShowWidget( $widget_args, $number = 1 ) {
        extract($widget_args);
        $title = $this->Options['widget_title'];
        $limit = $this->Options['widget_limit'];
        
        echo $before_widget;
        echo $before_title . $title . $after_title;
        echo "\n" . '<ul>';
        $this->GetPost('limit=' . $limit);
        echo '</ul>' . "\n";
        echo $after_widget;
    }
    
    /**
     * 添加管理菜单到WP
     *
     * @since 0.1
     */
    function AdminMenu() {
        add_options_page('FanfouPortable', 'FanfouPortable', 'manage_options', 'fanfouportable_admin_options_page', array(&$this, 'OptionsPage'));
    }

    /**
     * 在管理页面显示Fanfou最新状态
     *
     * @since 0.1
     * @return string
     */
    function StatusForConfigPage() {
        $cache = get_option('fanfouportable_cache');
        if (empty($cache))
            return "No Status!";
        $output = '
<ul id="fanfou-status">
    <li>
        <a target="_blank" title="' . $cache['user']['screen_name'] . '" href="' . $cache['user']['url'] . '"><img alt="' . $cache['user']['screen_name'] . '" src="' . $cache['user']['avatar'] . '" style="float: left; margin-right: 10px;"/></a>
        <a target="_blank" href="' . $cache['user']['url'] . '">' . $cache['user']['screen_name'] . '</a><br />
        <p>' . $cache['user']['description'] . '</p><br />    
    </li>';
        
        $output .= $this->GetPost("limit=20&echo=0") . "</ul>\n";
        return $output;
    }

    /**
     * 管理页面内容
     *
     * @since 0.1
     */
    function OptionsPage() {
        if (isset($_POST['update_options'])) {
            $this->UpdateOptions();
        } else if (isset($_POST['synchronous'])) {
            $this->Update();
        }
        
        echo('
    <script type="text/javascript">
        <!--
        jQuery(document).ready(function() {
            var query_url = "' . get_bloginfo('url') . '/wp-admin/options-general.php";
            jQuery("#login_test").click(function() {
                var usr = jQuery("input#username").attr("value");
                var psw = jQuery("input#password").attr("value");
                jQuery("#login_test_result").html("Testing...");
                jQuery.post(query_url,
                            {ffp_action: "login_test", username:usr, password:psw},
                            function(data) {
                                jQuery("#login_test_result").html(data);
                            });
            });
            
            jQuery("#send_message").click(function() {
                var msg = jQuery("input#message").attr("value");
                if (!msg) { 
                    alert("Message is empty!");
                    return;
                }
                jQuery("#message_send_result").html("Sending...");
                jQuery.post(query_url,
                            {ffp_action: "send_message", message:msg},
                            function(data) {
                                jQuery("#message_send_result").html(data);
                                if(data == "Send successful!") jQuery("#message").attr("value","");
                            });
            });
        });
        -->
    </script>
    <div class="wrap">
        <h2>FanFouPortable Options <span style="font:bold 10px verdana;">v' . FANFOUPORTABLE_VERSION . '</span></h2>
        <p>Settings for the FanFouPortable plugin. Visit <a href="http://jeeker.net/projects/fanfouportable/">Jeeker</a> for usage information and project news.</p>
        <form id="fanfouportable" name="fanfouportable" method="post">
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Login Information : </th>
                    <td>
                    ID or Email:&nbsp;<input type="text" size="25" name="username" id="username" value="' . $this->Options['username'] . '" />&nbsp;&nbsp;&nbsp;&nbsp;
                    Password:&nbsp;<input type="password" size="25" name="password" id="password" value="' . $this->Options['password'] . '" />&nbsp;&nbsp;&nbsp;&nbsp;
                    <input class="button" type="button" value="Test Login" id="login_test" name="login_test"/><br />
                    <span id="login_test_result"></span>
                    </td>
            </tr>
            <tr valign="top">
                <th scope="row">Message Send : </th>
                <td>
                    <input type="text" name="message" id="message" value="" size="74" />&nbsp;&nbsp;&nbsp;&nbsp;
                    <input class="button" type="button" value="Send Now" id="send_message" name="send_message"/><br />
                    <span id="message_send_result"></span>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Notifier : </th>
                <td>
                    <input type="checkbox" name="notify_enable" id="notify_enable" value="1"'. ($this->Options['notify_enable'] == 1 ? ' checked="checked"' : '') . ' /> Create a fanfou status when you publish a new blog post?<br />
                    Format:<input type="text" name="notify_format" id="notify_format" value="' . $this->Options['notify_format'] . '" size="50" /><br />
                    <em style="font:normal 10px verdana; color: gray;">
                        Format for notifier when publish a new blog post.<br />
                        Markers: %Post_Title%=>Post Title %Post_URL%=>Post URL %Blog_Name%=>Blog Name %Blog_URL%=>Blog URL .
                    </em>
                </td>
            </tr>    
            <tr valign="top">
                <th scope="row">Time interval for updating new posts</th>
                <td>
                    <input type="text" name="update_interval" id="update_interval" value="' . $this->Options['update_interval'] . '" size="6" /> seconds
                </td>
            </tr>                                
       </table>
       <p><input class="button" type="submit" name="update_options" value="Update Options &raquo;" />
          <input class="button" type="submit" name="reset_options" value="Reset Options &raquo;" /></p>
        </form>

        <form method="post" name="update_posts">
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Synchronous : </th>
                <td>
                    Use this button to manually update your fanfou status that show on your wordpress sidebar.<br/>
                    Last sync time: <strong>' . date('Y-m-d H:i:s', $this->Options['last_update_time']) . '</strong><br/>
                    Next sync time: <strong>' . date('Y-m-d H:i:s', $this->Options['last_update_time'] + $this->Options['update_interval']) . '</strong>
                </td>
            </tr>
        </table>
        <p><input class="button" type="submit" name="synchronous" value="Synchronous Fanfou Status Now &raquo;" /></p>
        </form>
        <style type="text/css" media="all">
            #fanfou-status {padding:20px 0 0 20px;list-style-type: none;font-size:12px;}
            #fanfou-status li {font:normal 12px verdana; color: gray;margin:5px 0 0 0;}
            #fanfou-status a:link { text-decoration: none;}
        </style>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Your Fanfou Status : </th>
                <td>
' . $this->StatusForConfigPage() . '
                </td>
            </tr>
        </table>

    </div>
        ');
    }
}

global $FanfouPortable;

/**
 * 初始化FanfouPortable
 *
 * @since 0.2
 */
function FFP_Init() {
    global $FanfouPortable;
    $FanfouPortable = new FanfouPortable();
}
add_action('plugins_loaded', 'FFP_Init');

/**
 * 获取Fanfou状态
 *
 * @since 0.1
 * @param string $args
 * @return null | string 当参数中echo=1时直接输出，否则返回HTML代码
 */
function FFP_GetPost($args='') {
    global $FanfouPortable;
    $FanfouPortable->GetPost($args);
}