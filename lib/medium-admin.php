<?php
// Copyright 2015 Medium
// Licensed under the Apache License, Version 2.0.

class Medium_Admin {

  private static $_initialised = false;

  /**
   * Initialises actions and filters.
   */
  public static function init() {
    if (self::$_initialised) return;
    self::$_initialised = true;

    session_start();

    add_action("admin_init", array("Medium_Admin", "admin_init"));
    add_action("admin_notices", array("Medium_Admin", "admin_notices"));

    add_action("show_user_profile", array("Medium_Admin", "show_user_profile"));
    add_action("edit_user_profile", array("Medium_Admin", "show_user_profile"));

    add_action("personal_options_update", array("Medium_Admin", "personal_options_update"));
    add_action("edit_user_profile_update", array("Medium_Admin", "personal_options_update"));

    add_action("add_meta_boxes_post", array("Medium_Admin", "add_meta_boxes_post"));

    add_action("save_post", array("Medium_Admin", "save_post"), 10, 2);
  }

  // Actions and hooks.

  /**
   * Initialises admin functionality.
   */
  public static function admin_init() {
    load_plugin_textdomain(MEDIUM_TEXTDOMAIN);

    wp_enqueue_script("medium_admin_js", MEDIUM_PLUGIN_URL . "js/admin.js", array(), MEDIUM_VERSION);
    wp_enqueue_style("medium_admin_css", MEDIUM_PLUGIN_URL . "css/admin.css", array(), MEDIUM_VERSION);
  }

  /**
   * Renders admin notices.
   */
  public static function admin_notices() {
    if (!$_SESSION["medium_notices"]) return;
    foreach ($_SESSION["medium_notices"] as $name => $args) {
      self::_render("notice-$name", $args);
    }
    $_SESSION["medium_notices"] = array();
  }

  /**
   * Handles the saving of personal options.
   */
  public static function personal_options_update($user_id) {
    if (!current_user_can("edit_user", $user_id)) return false;
    $token = $_POST["medium_integration_token"];
    $status = $_POST["medium_default_post_status"];
    $license = $_POST["medium_default_post_license"];

    $medium_user = self::_get_medium_connected_user($user_id);

    if ($medium_user->default_status != $status) {
      $medium_user->default_status = $status;
    }

    if ($medium_user->default_license != $license) {
      $medium_user->default_license = $license;
    }

    if (!$token) {
      $medium_user->id = "";
      $medium_user->image_url = "";
      $medium_user->name = "";
      $medium_user->token = "";
      $medium_user->url = "";
    } else if ($token != $medium_user->token) {
      try {
        // Check that the token is valid.
        $user = self::get_medium_user_info($token);
        $medium_user->id = $user->id;
        $medium_user->image_url = $user->imageUrl;
        $medium_user->name = $user->name;
        $medium_user->token = $token;
        $medium_user->url = $user->url;

        self::_add_notice("connected", array(
          "user" => $user
        ));
      } catch (Exception $e) {
        self::_add_api_error_notice($e, $token);
      }
    }
    self::_save_medium_connected_user($user_id, $medium_user);

    return true;
  }

  /**
   * Adds Medium integration settings to the user profile.
   */
  public static function show_user_profile($user) {
    $medium_user = self::_get_medium_connected_user($user->ID);
    self::_render("form-user-profile", array(
      "medium_post_statuses" => self::_get_post_statuses(),
      "medium_post_licenses" => self::_get_post_licenses(),
      "medium_user" => $medium_user
    ));
  }

  /**
   * Renders the cross-posting options in the edit post sidebar.
   */
  public static function add_meta_boxes_post($post) {
    add_meta_box("medium", "Medium", function ($post, $args) {
      global $current_user;

      $medium_logo_url = MEDIUM_PLUGIN_URL . 'i/logo.png';
      $medium_post = self::_get_medium_connected_post($post->ID);
      $medium_user = self::_get_medium_connected_user($current_user->ID);
      if ($medium_post->id) {
        // Already connected.
        self::_render("form-post-box-linked", array(
          "medium_post" => $medium_post,
          "medium_user" => $medium_user,
          "medium_logo_url" => $medium_logo_url
        ));
      } else if ($medium_user->token && $medium_user->id) {
        // Can be connected.
        if (!$medium_post->license) {
          $medium_post->license = $medium_user->default_license;
        }
        if (!$medium_post->status) {
          $medium_post->status = $medium_user->default_status;
        }
        $license_visibility_class = $medium_post->status == "none" ? "hidden" : "";
        self::_render("form-post-box-actions", array(
          "medium_post" => $medium_post,
          "medium_user" => $medium_user,
          "medium_logo_url" => $medium_logo_url,
          "medium_post_statuses" => self::_get_post_statuses(),
          "medium_post_licenses" => self::_get_post_licenses(),
          "license_visibility_class" => $license_visibility_class
        ));
      } else {
        // Needs token.
        self::_render("form-post-box-actions-disabled", array(
          "edit_profile_url" => get_edit_user_link($current_user->ID) . '#medium'
        ));
      }
    }, null, "side", "high");
  }

  /**
   * Save Medium metadata when a post is saved.
   * Potentially crossposts to Medium if the conditions are right.
   */
  public static function save_post($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $medium_post = self::_get_medium_connected_post($post_id);

    // If this post has already been sent to Medium, no need to do anything.
    if ($medium_post->id) return;

    if (isset($_REQUEST["medium-status"])) {
      $medium_post->status = $_REQUEST["medium-status"];
    }
    if (isset($_REQUEST["medium-license"])) {
      $medium_post->license = $_REQUEST["medium-license"];
    }

    // If the post isn't published, no need to do anything else.
    $published = $post->post_status == "publish";

    // If we don't want to crosspost this post to Medium, no need to do anything else.
    $skipCrossposting = $medium_post->status == "none";

    // If the user isn't connected, no need to do anything.
    $medium_user = self::_get_medium_connected_user($post->post_author);
    $connected = $medium_user->id && $medium_user->token;

    if (!$published || $skipCrossposting || !$connected) {
      // Save the updated license and status.
      self::_save_medium_connected_post($post_id, $medium_post);
      return;
    }

    // At this point, we are not auto-saving, the post is published, we are
    // connected, we haven't sent it to Medium previously, and we want to send it.

    try {
      $created_medium_post = self::create_medium_post($post, $medium_post->status, $medium_post->license, $medium_user);
    } catch (Exception $e) {
      self::_add_api_error_notice($e, $medium_user->token);
      return;
    }

    $medium_post->id = $created_medium_post->id;
    $medium_post->url = $created_medium_post->url;
    self::_save_medium_connected_post($post_id, $medium_post);

    self::_add_notice("published", array(
      "medium_post" => $medium_post,
      "medium_post_statuses" => self::_get_post_statuses()
    ));
    return;
  }

  // API calls.

  /**
   * Creates a post on Medium.
   */
  public static function create_medium_post($post, $status, $license, $medium_user) {
    $tag_data = wp_get_post_tags($post->ID);
    $tags = array();
    foreach ($tag_data as $tag) {
      if ($tag->taxonomy == "post_tag") {
        $tags[] = $tag->name;
      }
    }

    $content = self::_render("content-rendered-post", array(
      "title" => $post->post_title,
      "content" => wpautop($post->post_content),
      "cross_link" => true,
      "site_name" => get_bloginfo('name'),
      "permalink" => get_permalink($post->ID)
    ), true);

    $body = array(
      "title" => $post->post_title,
      "content" => $content,
      "tags" => $tags,
      "contentFormat" => "html",
      "canonicalUrl" => $permalink,
      "license" => $license,
      "publishStatus" => $status
    );
    $data = json_encode($body);

    $headers = array(
      "Authorization: Bearer " . $medium_user->token,
      "Content-Type: application/json",
      "Accept: application/json",
      "Accept-Charset: utf-8",
      "Content-Length: " . strlen($data)
    );

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://api.medium.com/v1/users/" . $medium_user->id . "/posts",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $data
    ));
    $result = curl_exec($curl);
    curl_close($curl);

    $payload = json_decode($result);
    if ($payload->errors) {
      $error = $payload->errors[0];
      throw new Exception($error->message, $error->code);
    }

    return $payload->data;
  }

  /**
   * Gets the Medium user's profile information.
   */
  public static function get_medium_user_info($integration_token) {
    $headers = array(
      "Authorization: Bearer " . $integration_token,
      "Accept: application/json",
      "Accept-Charset: utf-8"
    );

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://api.medium.com/v1/me",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers
    ));
    $result = curl_exec($curl);
    curl_close($curl);

    $payload = json_decode($result);
    if ($payload->errors) {
      $error = $payload->errors[0];
      throw new Exception($error->message, $error->code);
    }

    return $payload->data;
  }

  // Data.

  /**
   * Returns an array of the valid post statuses.
   */
  private static function _get_post_statuses() {
    return array(
      "none" => __("None", MEDIUM_TEXTDOMAIN),
      "public" => __("Public", MEDIUM_TEXTDOMAIN),
      "draft" => __("Draft", MEDIUM_TEXTDOMAIN),
      "unlisted" => __("Unlisted", MEDIUM_TEXTDOMAIN)
    );
  }

  /**
   * Returns an array of the the valid post licenses.
   */
  private static function _get_post_licenses() {
    return array(
      "all-rights-reserved" => __("All rights reserved", MEDIUM_TEXTDOMAIN),
      "cc-40-by" => __("CC 4.0 BY", MEDIUM_TEXTDOMAIN),
      "cc-40-by-nd" => __("CC 4.0 BY-ND", MEDIUM_TEXTDOMAIN),
      "cc-40-by-sa" => __("CC 4.0 BY-SA", MEDIUM_TEXTDOMAIN),
      "cc-40-by-nc" => __("CC 4.0 BY-NC", MEDIUM_TEXTDOMAIN),
      "cc-40-by-nc-nd" => __("CC 4.0 BY-NC-ND", MEDIUM_TEXTDOMAIN),
      "cc-40-by-nc-sa" => __("CC 4.0 BY-NC-SA", MEDIUM_TEXTDOMAIN),
      "cc-40-zero" => __("CC Copyright waiver", MEDIUM_TEXTDOMAIN),
      "public-domain" => __("Public domain", MEDIUM_TEXTDOMAIN)
    );
  }

  /**
   * Save the Medium post associated with the supplied post Id.
   */
  private static function _save_medium_connected_post($post_id, Medium_Post $medium_post) {
    update_post_meta($post_id, "medium_post_id", $medium_post->id);
    update_post_meta($post_id, "medium_post_license", $medium_post->license);
    update_post_meta($post_id, "medium_post_status", $medium_post->status);
    update_post_meta($post_id, "medium_post_url", $medium_post->url);
  }

  /**
   * Gets the Medium post associated with the supplied post Id.
   */
  private static function _get_medium_connected_post($post_id) {
    if (!$post_id) return new Medium_Post();

    $id = get_post_meta($post_id, "medium_post_id", true);
    $license = get_post_meta($post_id, "medium_post_license", true);
    $status = get_post_meta($post_id, "medium_post_status", true);
    $url = get_post_meta($post_id, "medium_post_url", true);
    return new Medium_Post($id, $license, $status, $url);
  }

  /**
   * Saves the Medium user associated with the supplied user Id.
   */
  private static function _save_medium_connected_user($user_id, Medium_User $medium_user) {
    update_usermeta($user_id, "medium_user_default_license", $medium_user->default_license);
    update_usermeta($user_id, "medium_user_default_status", $medium_user->default_status);
    update_usermeta($user_id, "medium_user_id", $medium_user->id);
    update_usermeta($user_id, "medium_user_image_url", $medium_user->image_url);
    update_usermeta($user_id, "medium_user_name", $medium_user->name);
    update_usermeta($user_id, "medium_integration_token", $medium_user->token);
    update_usermeta($user_id, "medium_user_url", $medium_user->url);
  }

  /**
   * Gets the Medium user associated with the supplied user Id.
   */
  private static function _get_medium_connected_user($user_id) {
    if (!$user_id) return new Medium_User();

    $default_license = get_the_author_meta("medium_user_default_license", $user_id);
    $default_status = get_the_author_meta("medium_user_default_status", $user_id);
    $id = get_the_author_meta("medium_user_id", $user_id);
    $image_url = get_the_author_meta("medium_user_image_url", $user_id);
    $name = get_the_author_meta("medium_user_name", $user_id);
    $token = get_the_author_meta("medium_integration_token", $user_id);
    $url = get_the_author_meta("medium_user_url", $user_id);
    return new Medium_User($default_license, $default_status, $id, $image_url, $name, $token, $url);
  }

  // Feedback.

  /**
   * Adds an API error notice.
   */
  public static function _add_api_error_notice(Exception $e, $token) {
    $args = array(
      "token" => $token
    );
    switch ($e->getCode()) {
      case 6000:
      case 6001:
      case 6003:
        $type = "invalid-token";
        break;
      case 6027:
        $type = "api-disabled";
        break;
      default:
        $args["message"] = $e->getMessage();
        $args["code"] = $e->getCode();
        $type = "something-wrong";
        break;
    }
    self::_add_notice($type, $args);
  }

  /**
   * Adds a notice to the admin panel.
   */
  private static function _add_notice($name, array $args = array()) {
    if (!$_SESSION["medium_notices"]) {
      $_SESSION["medium_notices"] = array();
    }
    $_SESSION["medium_notices"][$name] = $args;
  }

  // Rendering.

  /**
   * Renders a template.
   */
  private static function _render($name, array $args = array(), $return = false) {
    $data = new stdClass();
    foreach ($args as $key => $val) {
      $data->$key = $val;
    }
    ob_start();
    include(MEDIUM_PLUGIN_DIR . 'views/'. $name . '.phtml');
    if ($return) return ob_get_clean();
    ob_end_flush();
  }
}

/**
 * Representation of a Medium post.
 */
class Medium_Post {
  public $id;
  public $license;
  public $status;
  public $url;

  public function __construct($id, $license, $status, $url) {
    $this->id = $id;
    $this->license = $license;
    $this->status = $status;
    $this->url = $url;
  }
}

/**
 * Representation of a Medium user.
 */
class Medium_User {
  public $default_license;
  public $default_status;
  public $id;
  public $image_url;
  public $name;
  public $token;
  public $url;

  public function __construct($default_license, $default_status, $id, $image_url, $name, $token, $url) {
    $this->default_license = $default_license;
    $this->default_status = $default_status;
    $this->id = $id;
    $this->image_url = $image_url;
    $this->name = $name;
    $this->token = $token;
    $this->url = $url;
  }
}