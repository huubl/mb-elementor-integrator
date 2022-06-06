<?php

namespace MBEI;

use Elementor\Plugin;
use Elementor\Core\DynamicTags\Manager;

class GroupField {
    
    public function get_current_post() {
        global $post, $wp_query;

        if (!empty($post)) {
            return $post;
        }

        list( $post_type, $slug ) = explode( '/', $wp_query->query['pagename'] );
        $current_post = get_page_by_path( $slug, OBJECT, $post_type );
        return $current_post;
    }

    public function get_skin_template() {

        $cache_key = 'mbei_skin_template';
        $templates = wp_cache_get( $cache_key );
        if ( false === $templates ) {
            $templates = get_posts( [
                'post_type' => 'elementor_library',
                'numberposts' => -1,
                'post_status' => 'publish',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'elementor_library_type',
                        'field' => 'slug',
                        'terms' => 'metabox_group_template',
                    ),
                ),
            ] );
            wp_cache_set( $cache_key, $templates );
        }

        $options = [ 0 => __( 'Select a skin', 'mb-elementor-integrator' ) ];
        foreach ( $templates as $template ) {
            $options[ $template->ID ] = $template->post_title;
        }
        return $options;
    }
    
    public function get_placeholder_template( $template_id ) {
        $templates = $this->get_skin_template();
        $template_name = strtolower( $templates[ $template_id ] );
        return "{{ placeholder template $template_name }}"; 
    }
    
    public function get_template_settings( $template_id = null ) {
        $template = $this->get_id_support_wpml( $template_id );

        if ( !$template ) {
            return;
        }
        
        // Get elements settings.
        $document = Plugin::instance()->documents->get( $template );
        $settings = $document->get_elements_data();
        
        return [$settings, $template];
    }

    public function get_template( $template_id = null ) {
        // Get elements settings.
        list($settings, $template) = $this->get_template_settings( $template_id );
        if ( !$settings ) {
            return;
        }
        // Get data dynamic tags
        $dynamic_tags = [];
        $this->find_element_dynamic_tag( $settings, $dynamic_tags );
        $sub_group = isset( $dynamic_tags['sub-group'] ) ? $dynamic_tags['sub-group'] : [];
        unset( $dynamic_tags['sub-group'] );  

        $data_replace = $this->dynamic_tag_to_data( $dynamic_tags, Plugin::instance()->dynamic_tags );
        // Get Content Template.
        $content_template = Plugin::instance()->frontend->get_builder_content_for_display( $template, true );
        
        if ( 0 < count( $sub_group ) ) {
            libxml_use_internal_errors( true );
            $domTemplate = new \DOMDocument();
            $domTemplate->loadHTML( $content_template );
            libxml_clear_errors();
            $xpath = new \DOMXpath( $domTemplate );            
            foreach ( $sub_group as $sub ) {
                $widget_sub = $xpath->query( '//div[@data-id="'.$sub['id'].'"]//div[contains(@class,"mbei-sub-groups")]' );                
                if($widget_sub->length > 0) {
                    $widget_node = $widget_sub->item(0);
                    
                    $replacement  = $domTemplate->createDocumentFragment();
                    $replacement->appendXML( '<div class="'.$widget_node->getAttribute('class').'" data-id="'.$widget_node->getAttribute('data-id').'">'.$this->get_placeholder_template( $sub['template'] ).'</div>' );
                                       
                    $widget_node->parentNode->replaceChild( $replacement, $widget_node );
                    $content_template = $domTemplate->saveHTML( $domTemplate->documentElement );
                    $content_template = str_replace( [ '<html>', '</html>', '<head>', '</head>', '<body>', '</body>' ], '', $content_template );
                }
            }            
        }
        
//        if($template_id == 1291){
//            print_r($data_replace);
//            print_r($content_template);die();
//        }
        return [
            'data' => $data_replace,
            'content' => $content_template,
        ];
    }

    private function dynamic_tag_to_data( $dynamic_tags = [ ], Manager $dynamic_tags_mageger = null ) {
        if ( empty( $dynamic_tags ) || empty( $dynamic_tags_mageger ) ) {
            return [ ];
        }

        $data_replace = [ ];
        foreach ( $dynamic_tags as $dynamic_tag ) {
            // Chek $dynamic_tag is array.
            if ( is_array( $dynamic_tag ) ) {
                $data_replace = array_merge( $data_replace, $this->dynamic_tag_to_data( $dynamic_tag, $dynamic_tags_mageger ) );
                continue;
            }
            // Check $dynamic_tag is dynamic tag meta box.
            $tag_data = $dynamic_tags_mageger->tag_text_to_tag_data( $dynamic_tag );
            if ( false === strpos( $tag_data['name'], 'meta-box-' ) ) {
                continue;
            }
            // Get tag content.
            $tag_data_content = $dynamic_tags_mageger->get_tag_data_content( $tag_data['id'], $tag_data['name'], $tag_data['settings'] );
            $key = explode( ':', $tag_data['settings']['key'] )[ 1 ];
            if ( isset( $tag_data_content['url'] ) ) {
                $data_replace[ $key ]['content'] = self::change_url_ssl( $tag_data_content['url'] );
            } else {
                $data_replace[ $key ]['content'] = $tag_data_content;
            }
            $data_replace[ $key ]['template'] = isset( $tag_data['settings']['mb_skin_template'] ) ? $tag_data['settings']['mb_skin_template'] : '';
        }
        return $data_replace;
    }

    private function find_element_dynamic_tag( $settings, &$results = [ ] ) {
        if ( !is_array( $settings ) ) {
            return;
        }

        foreach ( $settings as $elements ) {
            if ( isset( $elements['elType'] ) && $elements['elType'] === 'widget' && isset( $elements['settings']['__dynamic__'] ) ) {
                $results = array_merge_recursive( $results, $elements['settings']['__dynamic__'] );
                continue;
            }
            
            if ( isset( $elements['elType'] ) && 'widget' === $elements['elType'] && isset( $elements['settings']['_skin'] ) && 'meta_box_skin_group' === $elements['settings']['_skin'] && !empty( $elements['settings']['mb_skin_template'] ) ) {
                $results = array_merge_recursive( $results, [
                    'editor' => '[elementor-tag id="'.dechex( rand( 1, 99999999 ) ).'" name="meta-box-text" settings="'. urlencode( json_encode( [ 'key' => str_replace( '.', ':', $elements['settings']['field-group'] ), 'mb_skin_template' => $elements['settings']['mb_skin_template'] ] ) ).'"]'
                ] );
                $results['sub-group'][ ] = [
                    'id'        => $elements['id'],
                    'template'  => $elements['settings']['mb_skin_template']
                ];
            }             

            $this->find_element_dynamic_tag( $elements, $results );
        }
    }

    // Support multilang for WPML
    private function get_id_support_wpml( $id ) {
        $newid = apply_filters( 'wpml_object_id', $id, 'elementor_library', true );
        return $newid ? $newid : $id;
    }

    public function parse_options( $fields = [ ], $field_group_id = null ) {
        if ( empty( $fields ) || !isset( $fields['fields'] ) || empty( $fields['fields'] ) || empty( $field_group_id ) ) {
            return [ ];
        }

        $sub_fields = [ ];
        foreach ( $fields['fields'] as $field ) {
            $sub_fields[ $field_group_id . ':' . $field['id'] ] = $field['name'];
        }

        return $sub_fields;
    }

    public function get_field_group( $key = null ) {
        $field_registry = rwmb_get_registry('field');
        $post_types = $field_registry->get_by_object_type('post');

        $return_fields = [];
        if ( 0 < count( $post_types ) ) {
            foreach ( $post_types as $fields ) {
                // Fields is empty
                if ( 0 === count( $fields ) ) {
                    continue;
                }
                // get list field type=group
                $group_fields = $this->get_field_type_group( $fields );
                if ( 0 === count( $group_fields ) ) {
                    continue;
                }

                foreach ( $group_fields as $group_field ) {
                    if ( !empty( $key ) && $key !== $group_field['id'] ) {
                        continue;
                    }

                    array_push( $return_fields, $group_field );
                }
            }
        }

        if ( !empty( $key ) && 0 < count( $return_fields ) ) {
            return $return_fields[ 0 ];
        }

        return array_filter( $return_fields );
    }

    public function get_list_field_group() {
        $fields = $this->get_field_group();
        $list = [ ];
        foreach ( $fields as $k => $field ) {
            if ( in_array( $field['id'], $list ) ) {
                continue;
            }

            if ( strpos( $field['id'], '.' ) !== false ) {
                $field_group = explode( '.', $field['id'] );
                $is_field_group = array_search( $field_group[0], array_column( $fields, 'id' ) );

                $label_group = !empty( $fields[ $is_field_group ]['name'] ) ? $fields[ $is_field_group ]['name'] : $fields[ $is_field_group ]['group_title'];
                $list[ $field['id'] ] = (!empty($field['name']) ? $field['name'] : $field['group_title'] ) . ' ( ' . $label_group . ' )';
                continue;
            }

            $list[ $field['id'] ] = !empty( $field['name'] ) ? $field['name'] : $field['group_title'];
        }
        return $list;
    }

    /**
     * Check Type field group
     * @param array $fields
     * @return array $return_fields fields of type group
     */
    private function get_field_type_group( $fields, $nested = '' ) {
        // not field type is group.
        $is_field_group = array_search( 'group', array_column( $fields, 'type' ) );
        if ( false === $is_field_group ) {
            return [ ];
        }

        $return_fields = [ ];
        foreach ( $fields as $field ) {
            if ( 'group' === $field['type'] ) {
                if ( !empty( $nested ) ) {
                    $field['id'] = $nested . '.' . $field['id'];
                }
                $return_fields[ ] = $field;
                if ( isset( $field['fields'] ) && 0 < count( $field['fields'] ) ) {
                    $return_fields = array_merge( $return_fields, $this->get_field_type_group( $field['fields'], $field['id'] ) );
                }
            }
        }

        return $return_fields;
    }

    public function get_value_nested_group( $values = [], $keys = [], $first_item = false ) {
        if ( empty( $keys ) || empty( $values ) ) {
            return [];
        }

        if ( false === is_int( key( $values ) ) ) {
            $values = [ $values ];
        }

        if ( true === $first_item ) {
            $values = [ array_shift( $values ) ];
        }

        $return = [];
        $match_keys = [];
        foreach ( $values as $index => $value ) {

            if ( $index > 0 ) {
                $match_keys = [];
            }

            foreach ( $keys as $key ) {
                if ( !isset( $value[$key] ) ) {
                    continue;
                }

                $match_keys[] = $key;
                if ( $key !== end( $keys ) ) {
                    $return = array_merge( $return, $this->get_value_nested_group( $value[ $key ], array_diff( $keys, $match_keys ) ) );
                    continue;
                }

                $return = array_merge( $return, (array) $value[ $key ] );
            }
        }

        return $return;
    }

    public function split_field_nested( $fields = [] ) {
        if ( empty( $fields ) || !is_array( $fields ) ) {
            return $fields;
        }

        $return = [];
        foreach ( $fields as $key => $value ) {
            if ( strpos( $value, '.' ) === false ) {
                continue;
            }

            $keys = explode( '.', $value, 2 );
            $fields[ $key ] = $keys[ 0 ];
            $return['sub_cols'][ $keys[ 0 ] ][] = $keys[ 1 ];
        }

        $return['cols'] = $fields;
        return $return;
    }

    public static function change_url_ssl( $url ) {
        if ( is_ssl() && false === strpos( $url, 'https' ) ) {
            return str_replace( 'http', 'https', $url );
        }
        return $url;
    }

    public function render_nested_group( $data_groups, $data_column ) {
        if ( false === is_int( key( $data_groups ) ) ) {
            $data_groups = [ $data_groups ];
        }

        foreach ( $data_groups as $data_group ) {
            echo '<div class="mbei-group mbei-group-nested">';
            foreach ( $data_group as $key => $value ) {
                if ( !isset( $data_column[ $key ] ) ) {
                    continue;
                }

                echo '<div class="mbei-subfield mbei-subfield--' . $key . '">';
                if ( is_array( $value ) && !empty( $value ) ) {
                    $data_column[ $key ]['fields'] = array_combine( array_column( $data_column[ $key ]['fields'], 'id'), $data_column[ $key ]['fields'] );
                    $this->render_nested_group( $value, $data_column[ $key ]['fields'] );
                    continue;
                }
                $this->display_field( $value, $data_column[ $key ] );
                echo '</div>';
            }
            echo '</div>';
        }
    }

    public function display_data_template( $template_id, $data_groups, $data_column, $options = ['loop_header' => '', 'loop_footer' => '' ] ) {
        $content_template = $this->get_template( $template_id );        
        $cols = array_keys( $content_template['data'] );

        if ( stripos( json_encode( $cols ), '.' ) !== false ) {
            $tmp_cols = $this->split_field_nested( $cols );
        }

        foreach ( $data_groups as $k => $data_group ) {
            $check_cols = array_intersect( array_keys( $data_group ), $cols );
            if ( 0 === count( $check_cols ) && !isset( $tmp_cols ) ) {
                continue;
            }

            $content = $options['loop_header'] . $content_template['content'] . $options['loop_footer'];
            foreach ( $cols as $col ) {
                if ( !isset( $data_group[ $col ] ) && false === strpos( $col, '.' ) ) {
                    continue;
                }

                if ( false !== strpos( $col, '.' ) ) {
                    $tmp_col = explode( '.', $col, 2 );
                    array_shift( $tmp_col );

                    $data_sub_column = array_filter( $data_column, function( $k ) use( $tmp_col ) {
                        return $k == $tmp_col[ 0 ];
                    }, ARRAY_FILTER_USE_KEY);

                    ob_start();
                    $this->render_nested_group( $data_group, $data_sub_column );
                    $value = ob_get_contents();
                    ob_end_clean();
                    
                    //Display text from sub field group.
                    if ( !isset( $data_sub_column[ $tmp_col[ 0 ] ]['mime_type'] ) || 'image' !== $data_sub_column[ $tmp_col[ 0 ] ]['mime_type'] ) {
                        $content = str_replace( $content_template['data'][ $col ]['content'], $value, $content );
                        continue;
                    }

                    //Display image from sub field group.
                    $search_data = [];
                    libxml_use_internal_errors( true );
                    $dom = new \DOMDocument();
                    $dom->loadHTML( $content );
                    foreach ( $dom->getElementsByTagName( 'img' ) as $i => $img ) {
                        if ( false === strpos( $img->getAttribute( 'srcset' ), $content_template['data'][ $col ]['content'] ) ) {
                            continue;
                        }
                        $search_data = [
                            'html' => str_replace('>', ' />', $dom->saveHTML( $img ) ),
                            'width' => 'width="' . $img->getAttribute( 'width' ) . '"',
                            'height' => 'height="' . $img->getAttribute( 'height' ) . '"',
                            'class' => 'class="' . $img->getAttribute( 'class' ) . '"'
                        ];
                    }

                    if ( empty( $search_data ) ) {
                        continue;
                    }

                    //Replace Attribute Image
                    $domNew = new \DOMDocument();
                    $domNew->loadHTML( $value );
                    foreach ( $domNew->getElementsByTagName( 'img' ) as $i => $img ) {
                        $value = str_replace( [
                            'width="' . $img->getAttribute( 'width' ) . '"',
                            'height' => 'height="' . $img->getAttribute( 'height' ) . '"',
                            'class="' . $img->getAttribute( 'class' ) . '"'
                                ], [
                            $search_data[ 'width' ],
                            $search_data[ 'height' ],
                            $search_data[ 'class' ]
                                ], $value );
                    }

                    $content = str_replace($search_data['html'], $value, $content);
                    continue;
                }

                //Get content field group
                if ( is_array( $data_group[ $col ] ) && !empty( $data_group[ $col ] ) ) {
                    $data_sub_column = array_combine( array_column( $data_column[ $col ]['fields'], 'id' ), $data_column[ $col ]['fields'] );
                    if ( !empty( $content_template['data'][ $col ]['template'] ) ) {
                        ob_start();
                        $this->display_data_template( $content_template['data'][ $col ]['template'], $data_group[ $col ], $data_sub_column, $options );
                        $value = ob_get_contents();
                        ob_end_clean();
                        
                        $content = str_replace( $content_template['data'][ $col ]['content'], $value, $content );
                        continue;                        
                    }

                    ob_start();
                    $this->render_nested_group( $data_group[ $col ], $data_sub_column );
                    $value = ob_get_contents();
                    ob_end_clean();

                    $content = str_replace( $content_template['data'][ $col ]['content'], $value, $content );
                    continue;
                }

                ob_start();
                $this->display_field( $data_group[ $col ], $data_column[ $col ], true );
                $value = ob_get_contents();
                ob_end_clean();

                $content = str_replace( $content_template['data'][ $col ]['content'], $value, $content );
            }
            echo $content;
        }
    }

    public function display_field( $data, $field = [ ], $return = false ) {

        switch ( $field['type'] ) {
            case 'image':
            case 'image_advanced':
            case 'image_select':
            case 'image_upload':
            case 'single_image':
                $file_type = 'image';
                break;
            default:
                $file_type = 'text';
                break;
        }

        $path_file = plugin_dir_path(__DIR__) . 'src/Templates/display_field-' . $file_type . '.php';

        if ( file_exists( $path_file ) ) {
            require $path_file;
        }
    }

}
