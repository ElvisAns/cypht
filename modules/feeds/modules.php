<?php

if (!defined('DEBUG_MODE')) { die(); }

require 'lib/hm-feed.php';

class Hm_Handler_process_add_feed extends Hm_Handler_Module {
    public function process($data) {
        if (isset($this->request->post['submit_feed'])) {
            list($success, $form) = $this->process_form(array('new_feed_name', 'new_feed_address'));
            if ($success) {
                $found = false;
                $connection_test = address_from_url($form['new_feed_address']);
                if ($con = @fsockopen($connection_test, 80, $errno, $errstr, 2)) {
                    $feed = is_feed($form['new_feed_address']);
                    if (!$feed) {
                        $feed = new Hm_Feed();
                        $homepage = $feed->get_feed_data($form['new_feed_address']);
                        if (trim($homepage)) {
                            list($type, $href) = search_for_feeds($homepage);
                            if ($type && $href) {
                                Hm_Msgs::add('Discovered a feed at that address');
                                $found = true;
                            }
                            else {
                                Hm_Msgs::add('ERRCould not find an RSS or ATOM feed at that address');
                            }
                        }
                        else {
                            Hm_Msgs::add('ERRCound not find a feed at that address');
                        }
                    }
                    else {
                        Hm_Msgs::add('Successfully connected to feed');
                        $found = true;
                        if (stristr('<feed', $feed->xml_data)) {
                            $type = 'application/atom+xml';
                        }
                        else {
                            $type = 'application/rss+xml';
                        }
                        $href = $form['new_feed_address'];
                    }
                }
                else {
                    Hm_Msgs::add(sprintf('ERRCound not add feed: %s', $errstr));
                }
            }
            else {
                Hm_Msgs::add('ERRFeed Name and Address are required');
            }
            if ($found) {
                Hm_Feed_List::add(array(
                    'name' => $form['new_feed_name'],
                    'server' => $href,
                    'tls' => false,
                    'port' => 80
                ));
            }
        }
    }
}

class Hm_Handler_load_feeds_from_config extends Hm_Handler_Module {
    public function process($data) {
        $feeds = $this->user_config->get('feeds', array());
        foreach ($feeds as $index => $feed) {
            Hm_Feed_List::add($feed, $index);
        }
        return $data;
    }
}

class Hm_Handler_add_feeds_to_page_data extends Hm_Handler_Module {
    public function process($data) {
        $feeds = Hm_Feed_List::dump();
        if (!empty($feeds)) {
            $data['feeds'] = $feeds;
            $data['folder_sources'][] = 'feeds_folders';
        }
        return $data;
    }
}

class Hm_Handler_save_feeds extends Hm_Handler_Module {
    public function process($data) {
        $feeds = Hm_Feed_List::dump();
        $this->user_config->set('feeds', $feeds);
        return $data;
    }
}

class Hm_Output_add_feed_dialog extends Hm_Output_Module {
    protected function output($input, $format) {
        if ($format == 'HTML5') {
            return '<div class="imap_server_setup"><div class="content_title">Feeds</div><form class="add_server" method="POST">'.
                '<input type="hidden" name="hm_nonce" value="'.$this->build_nonce('add_feed').'"/>'.
                '<div class="subtitle">Add an RSS/ATOM Feed</div><table>'.
                '<tr><td colspan="2"><input type="text" name="new_feed_name" class="txt_fld" value="" placeholder="Feed name" /></td></tr>'.
                '<tr><td colspan="2"><input type="text" name="new_feed_address" class="txt_fld" placeholder="Site address or feed URL" value=""/></td></tr>'.
                '<tr><td align="right"><input type="submit" value="Add" name="submit_feed" /></td></tr>'.
                '</table></form>';
        }
    }
}

class Hm_Handler_load_feed_folders extends Hm_Handler_Module {
    public function process($data) {
        $feeds = Hm_Feed_List::dump();
        $folders = array();
        if (!empty($feeds)) {
            foreach ($feeds as $id => $feed) {
                $folders[$id] = $feed['name'];
            }
        }
        $data['feed_folders'] = $folders;
        return $data;
    }
}
class Hm_Output_display_configured_feeds extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '';
        if ($format == 'HTML5') {
            if (isset($input['feeds'])) {
                foreach ($input['feeds'] as $index => $vals) {
                    $res .= '<div class="configured_server">';
                    $res .= sprintf('<div class="server_title">%s</div><div class="server_subtitle">%s</div>', $this->html_safe($vals['name']), $this->html_safe($vals['server']));
                    $res .= '<form class="feed_connect" method="POST">';
                    $res .= '<input type="hidden" name="feed_id" value="'.$this->html_safe($index).'" />';
                    $res .= '<input type="submit" value="Test" class="test_feed_connect" />';
                    $res .= '<input type="submit" value="Delete" class="feed_delete" />';
                    $res .= '<input type="hidden" value="ajax_feed_debug" name="hm_ajax_hook" />';
                    $res .= '</form></div></div>';
                }
            }
        }
        return $res;
    }
}

class Hm_Output_feed_ids extends Hm_Output_Module {
    protected function output($input, $format) {
        if (isset($input['feed_ids'])) {
            return '<input type="hidden" class="feed_ids" value="'.$this->html_safe(implode(',', array_keys($input['feed_ids']))).'" />';
        }
    }
}

class Hm_Output_filter_feed_folders extends Hm_Output_Module {
    protected function output($input, $format) {
        $res = '<ul class="folders">';
        if (isset($input['feed_folders'])) {
            foreach ($input['feed_folders'] as $id => $folder) {
                $res .= '<li class="feed_'.$this->html_safe($id).'">'.
                    '<a href="?page=message_list&list_path=feed_'.$this->html_safe($id).'">'.
                    '<img class="account_icon" alt="Toggle folder" src="images/open_iconic/folder-2x.png" /> '.
                    $this->html_safe($folder).'</a></li>';
            }
        }
        $res .= '</ul>';
        Hm_Page_Cache::add('feeds_folders', $res, true);
        return '';
    }
}

function address_from_url($str) {
    $res = $str;
    $url_bits = parse_url($str);
    if (isset($url_bits['scheme']) && isset($url_bits['host'])) {
        $res = $url_bits['host'];
    }
    return $res;
}

function is_feed($url) {
    $feed = new Hm_Feed();
    $feed->parse_feed($url);
    $feed_data = array_filter($feed->parsed_data);
    if (empty($feed_data)) {
        return false;
    }
    else {
        return $feed;
    }
}

function search_for_feeds($html) {
    $type = false;
    $href = false;
    if (preg_match_all("/<link.+>/U", $html, $matches)) {
        foreach ($matches[0] as $link_tag) {
            if (stristr($link_tag, 'alternate')) {
                if (preg_match("/type=(\"|'|)(.+)(\"|'|\>| )/U", $link_tag, $types)) {
                    $type = trim($types[2]);
                }
                if (preg_match("/href=(\"|'|)(.+)(\"|'|\>| )/U", $link_tag, $hrefs)) {
                    $href = trim($hrefs[2]);
                }
            }
        }
    }
    return array($type, $href);
}
class Hm_Output_feed_message_list extends Hm_Output_Module {
    protected function output($input, $format) {
        if (isset($input['list_path']) && preg_match("/^feed_/", $input['list_path'])) {
            return feed_message_list($input, $this);
        }
        else {
            // TODO: default
        }
    }
}
function feed_message_list($input, $output_module) {
    $title = implode('<img class="path_delim" src="images/open_iconic/caret-right.png" alt="&gt;" />', $input['mailbox_list_title']);
    return '<div class="message_list"><div class="content_title">'.$title.
        '<a class="update_unread" href="#"  onclick="return select_pop3_folder(\''.$output_module->html_safe($input['list_path']).'\', true)">[update]</a></div>'.
        '<table class="message_table" cellpadding="0" cellspacing="0"><colgroup><col class="source_col">'.
        '<col class="subject_col"><col class="from_col"><col class="date_col"></colgroup>'.
        '<thead><tr><th>Source</th><th>Subject</th><th>From</th><th>Date</th></tr></thead>'.
        '<tbody></tbody></table><div class="pop3_page_links"></div></div>';
}

?>
