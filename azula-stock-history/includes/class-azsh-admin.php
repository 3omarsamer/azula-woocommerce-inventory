<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AZSH_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'variation_button' ), 30, 3 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'wp_ajax_azsh_variation_history', array( __CLASS__, 'ajax_history' ) );
        add_action( 'wp_ajax_azsh_update_stock', array( __CLASS__, 'ajax_update_stock' ) );
        add_action( 'wp_ajax_azsh_product_skus', array( __CLASS__, 'ajax_product_skus' ) );
        add_action( 'wp_ajax_azsh_category_change', array( __CLASS__, 'ajax_category_change' ) );
        add_action( 'admin_post_azsh_export_inventory', array( __CLASS__, 'export_inventory_csv' ) );
    }

    public static function menu() {
        add_submenu_page( 'woocommerce', 'Inventory', 'Inventory', 'manage_woocommerce', 'azula-stock-history', array( __CLASS__, 'router' ) );
    }

    public static function assets( $hook ) {
        if ( 'post.php' !== $hook && 'woocommerce_page_azula-stock-history' !== $hook ) { return; }
        wp_enqueue_style( 'azsh-admin', plugins_url( '../assets/admin.css', __FILE__ ), array(), AZSH_VERSION );
        wp_enqueue_script( 'azsh-admin', plugins_url( '../assets/admin.js', __FILE__ ), array( 'jquery' ), AZSH_VERSION, true );
        wp_localize_script( 'azsh-admin', 'AZSH', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'azsh_history' ) ) );
    }

    public static function variation_button( $loop, $variation_data, $variation ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        echo '<p class="form-row form-row-full"><button type="button" class="button azsh-view-history" data-variation="' . esc_attr( $variation->ID ) . '">View Stock History</button></p>';
    }

    public static function router() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'lookup';
        echo '<div class="wrap"><h1>Inventory</h1><nav class="nav-tab-wrapper azsh-tabs">';
        echo '<a class="nav-tab '.('lookup'===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=azula-stock-history&tab=lookup')).'">Stock Lookup</a>';
        echo '<a class="nav-tab '.('history'===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=azula-stock-history&tab=history')).'">Stock History</a>';
        echo '<a class="nav-tab '.('category-audit'===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=azula-stock-history&tab=category-audit')).'">Category Audit</a></nav>';
        if ('history'===$tab) { self::page(false); } elseif ('category-audit'===$tab) { self::category_audit_page(); } else { self::lookup_page(); }
        echo '</div>';
    }

    public static function lookup_page() {
        global $wpdb;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $stock_filter = isset($_GET['stock']) ? sanitize_key(wp_unslash($_GET['stock'])) : '';
        $category = isset($_GET['category']) ? absint($_GET['category']) : 0;
        $product_status = isset($_GET['product_status']) ? sanitize_key(wp_unslash($_GET['product_status'])) : '';
        $sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'stock';
        if ( ! in_array( $sort, array('stock','product','status','price','value'), true ) ) $sort='stock';
        $order = (isset($_GET['order']) && 'asc'===sanitize_key(wp_unslash($_GET['order']))) ? 'asc' : 'desc';
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per=50; $offset=($paged-1)*$per;
        $lookup=$wpdb->wc_product_meta_lookup; $posts=$wpdb->posts;

        $where=array("p.post_type IN ('product','product_variation')","p.post_status NOT IN ('trash','auto-draft')","NOT (p.post_type='product' AND EXISTS (SELECT 1 FROM {$posts} vx WHERE vx.post_parent=p.ID AND vx.post_type='product_variation' AND vx.post_status NOT IN ('trash','auto-draft')))");
        $args=array();

        if ($search!=='') {
            $like='%'.$wpdb->esc_like($search).'%';
            $where[]='(l.sku LIKE %s OR p.post_title LIKE %s OR parent.post_title LIKE %s)';
            array_push($args,$like,$like,$like);
        }
        if ($category) {
            $term_taxonomy_ids = get_term_children($category, 'product_cat');
            if (is_wp_error($term_taxonomy_ids)) $term_taxonomy_ids=array();
            $term_taxonomy_ids[]=$category;
            $term_taxonomy_ids=array_values(array_unique(array_map('absint',$term_taxonomy_ids)));
            $placeholders=implode(',',array_fill(0,count($term_taxonomy_ids),'%d'));
            $where[]="EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                WHERE tr.object_id=(CASE WHEN p.post_type='product_variation' THEN p.post_parent ELSE p.ID END)
                  AND tt.taxonomy='product_cat' AND tt.term_id IN ({$placeholders})
            )";
            $args=array_merge($args,$term_taxonomy_ids);
        }
        if ('in'===$stock_filter) $where[]='l.stock_quantity > 0';
        elseif ('out'===$stock_filter) $where[]='(l.stock_quantity IS NULL OR l.stock_quantity <= 0)';
        elseif ('low'===$stock_filter) $where[]='l.stock_quantity > 0 AND l.stock_quantity <= 2';

        // A variation inherits its staff-facing product status from its parent product.
        if ('active'===$product_status) $where[]="(CASE WHEN p.post_type='product_variation' THEN parent.post_status ELSE p.post_status END)='publish'";
        elseif ('draft'===$product_status) $where[]="(CASE WHEN p.post_type='product_variation' THEN parent.post_status ELSE p.post_status END)='draft'";

        $join=" FROM {$lookup} l INNER JOIN {$posts} p ON p.ID=l.product_id LEFT JOIN {$posts} parent ON parent.ID=p.post_parent ";
        $ws=' WHERE '.implode(' AND ',$where);
        $count_sql="SELECT COUNT(*)".$join.$ws;
        $total=(int)$wpdb->get_var($args?$wpdb->prepare($count_sql,$args):$count_sql);

        $dir='asc'===$order?'ASC':'DESC';
        if ('product'===$sort) {
            $order_sql="COALESCE(NULLIF(parent.post_title,''),p.post_title) {$dir}, COALESCE(NULLIF(l.sku,''),'') {$dir}, l.product_id {$dir}";
        } elseif ('status'===$sort) {
            $order_sql="(CASE WHEN p.post_type='product_variation' THEN parent.post_status ELSE p.post_status END) {$dir}, COALESCE(NULLIF(parent.post_title,''),p.post_title) ASC, l.sku ASC";
        } elseif ('price'===$sort) {
            $order_sql="(l.min_price IS NULL) ASC, l.min_price {$dir}, l.product_id DESC";
        } elseif ('value'===$sort) {
            $order_sql="((COALESCE(l.stock_quantity,0)) * (COALESCE(l.min_price,0))) {$dir}, l.product_id DESC";
        } else {
            $order_sql="(l.stock_quantity IS NULL) ASC, l.stock_quantity {$dir}, l.product_id DESC";
        }

        $sql="SELECT l.product_id,l.sku,l.stock_quantity,l.stock_status,p.post_type,p.post_parent,p.post_title,p.post_status,parent.post_title parent_title,parent.post_status parent_status".$join.$ws." ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,array_merge($args,array($per,$offset))));

        echo '<h2>Stock Lookup</h2><p>Current WooCommerce stock by SKU. 50 rows per page.</p>';
        echo '<form method="get" class="azsh-lookup-filters"><input type="hidden" name="page" value="azula-stock-history"><input type="hidden" name="tab" value="lookup">';
        echo '<input class="azsh-search" name="s" placeholder="Search product name or SKU" value="'.esc_attr($search).'"> ';
        echo '<select name="stock"><option value="">All stock</option><option value="in" '.selected($stock_filter,'in',false).'>In stock</option><option value="low" '.selected($stock_filter,'low',false).'>Low stock (1–2)</option><option value="out" '.selected($stock_filter,'out',false).'>Out of stock</option></select> ';
        echo '<select name="category"><option value="0">All categories</option>';
        $cats=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'orderby'=>'name','order'=>'ASC'));
        if(!is_wp_error($cats)) foreach($cats as $cat) {
            // Count inventory SKU rows assigned to this category (including child categories).
            $ids=get_term_children($cat->term_id,'product_cat'); if(is_wp_error($ids))$ids=array(); $ids[]=$cat->term_id;
            $ids=array_values(array_unique(array_map('absint',$ids))); $ph=implode(',',array_fill(0,count($ids),'%d'));
            $count_sql="SELECT COALESCE(SUM(CASE WHEN cl.stock_quantity > 0 THEN cl.stock_quantity ELSE 0 END),0) FROM {$wpdb->wc_product_meta_lookup} cl INNER JOIN {$wpdb->posts} cp ON cp.ID=cl.product_id WHERE cp.post_type IN ('product','product_variation') AND cp.post_status NOT IN ('trash','auto-draft') AND NOT (cp.post_type='product' AND EXISTS (SELECT 1 FROM {$wpdb->posts} cvx WHERE cvx.post_parent=cp.ID AND cvx.post_type='product_variation' AND cvx.post_status NOT IN ('trash','auto-draft'))) AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} ctr INNER JOIN {$wpdb->term_taxonomy} ctt ON ctt.term_taxonomy_id=ctr.term_taxonomy_id WHERE ctr.object_id=(CASE WHEN cp.post_type='product_variation' THEN cp.post_parent ELSE cp.ID END) AND ctt.taxonomy='product_cat' AND ctt.term_id IN ({$ph}))";
            $cc=(float)$wpdb->get_var($wpdb->prepare($count_sql,$ids));
            $cc=self::format_qty($cc);
            echo '<option value="'.esc_attr($cat->term_id).'" '.selected($category,$cat->term_id,false).'>'.esc_html($cat->name.' ('.$cc.')').'</option>';
        }
        echo '</select> ';
        echo '<select name="product_status"><option value="">All statuses</option><option value="active" '.selected($product_status,'active',false).'>Active</option><option value="draft" '.selected($product_status,'draft',false).'>Draft</option></select> ';
        echo '<button class="button button-primary">Search</button> <a class="button" href="'.esc_url(admin_url('admin.php?page=azula-stock-history&tab=lookup')).'">Clear</a> ';
        $export=wp_nonce_url(add_query_arg(array('action'=>'azsh_export_inventory','s'=>$search,'stock'=>$stock_filter,'category'=>$category,'product_status'=>$product_status,'sort'=>$sort,'order'=>$order),admin_url('admin-post.php')),'azsh_export_inventory');
        echo '<a class="button" href="'.esc_url($export).'">Export CSV</a></form>';
        echo '<p><strong>'.esc_html(number_format_i18n($total)).'</strong> rows.</p>';

        $base_sort_args=array('page'=>'azula-stock-history','tab'=>'lookup','s'=>$search,'stock'=>$stock_filter,'category'=>$category,'product_status'=>$product_status);
        $product_next=('product'===$sort && 'asc'===$order)?'desc':'asc';
        $stock_next=('stock'===$sort && 'desc'===$order)?'asc':'desc';
        $status_next=('status'===$sort && 'asc'===$order)?'desc':'asc';
        $product_url=add_query_arg(array_merge($base_sort_args,array('sort'=>'product','order'=>$product_next)),admin_url('admin.php'));
        $stock_url=add_query_arg(array_merge($base_sort_args,array('sort'=>'stock','order'=>$stock_next)),admin_url('admin.php'));
        $status_url=add_query_arg(array_merge($base_sort_args,array('sort'=>'status','order'=>$status_next)),admin_url('admin.php'));
        $price_next=('price'===$sort && 'desc'===$order)?'asc':'desc';
        $value_next=('value'===$sort && 'desc'===$order)?'asc':'desc';
        $price_url=add_query_arg(array_merge($base_sort_args,array('sort'=>'price','order'=>$price_next)),admin_url('admin.php'));
        $value_url=add_query_arg(array_merge($base_sort_args,array('sort'=>'value','order'=>$value_next)),admin_url('admin.php'));

        echo '<table class="widefat striped azsh-lookup-table"><thead><tr>';
        echo '<th class="azsh-image-col">Photo</th>';
        echo '<th><a href="'.esc_url($product_url).'">Product / SKU'.('product'===$sort?('asc'===$order?' ↑':' ↓'):'').'</a></th>';
        echo '<th class="azsh-stock-col"><a href="'.esc_url($stock_url).'">Current Stock'.('stock'===$sort?('asc'===$order?' ↑':' ↓'):'').'</a></th>';
        echo '<th><a href="'.esc_url($price_url).'">Price'.('price'===$sort?('asc'===$order?' ↑':' ↓'):'').'</a></th>';
        echo '<th class="azsh-total-value-col" '.('value'===$sort?'':'hidden').'><a href="'.esc_url($value_url).'">Total Value'.('value'===$sort?('asc'===$order?' ↑':' ↓'):'').'</a></th>';
        echo '<th><button type="button" class="button-link azsh-toggle-value" title="Show/hide total value" aria-label="Show/hide total value">👁</button></th>';
        echo '<th>Stock Status</th><th>Actions</th>';
        echo '<th><a href="'.esc_url($status_url).'">Product Status'.('status'===$sort?('asc'===$order?' ↑':' ↓'):'').'</a></th>';
        echo '</tr></thead><tbody>';

        if (!$rows) echo '<tr><td colspan="9">No matching products or SKUs.</td></tr>';
        foreach($rows as $r) {
            $product=wc_get_product((int)$r->product_id); if(!$product) continue;
            $name=$product->get_name();
            $parent_id=$product->is_type('variation')?$product->get_parent_id():$product->get_id();
            $edit=get_edit_post_link($parent_id,'raw'); if($product->is_type('variation')&&$edit) $edit.='#variation_'.$product->get_id();

            $imgid=$product->get_image_id();
            if(!$imgid&&$product->is_type('variation')) { $par=wc_get_product($product->get_parent_id()); $imgid=$par?$par->get_image_id():0; }
            $img=$imgid?wp_get_attachment_image($imgid,array(56,70),false,array('class'=>'azsh-thumb')):wc_placeholder_img(array(56,70));

            $qty=$product->get_stock_quantity('edit'); $qd=is_null($qty)?'—':self::format_qty($qty);
            $st=$product->get_stock_status(); $label='instock'===$st?'In stock':('onbackorder'===$st?'On backorder':'Out of stock');
            if(!is_null($qty)&&$qty>0&&$qty<=2) $label='Low stock';

            $raw_status=$product->is_type('variation') ? get_post_status($parent_id) : get_post_status($product->get_id());
            $display_status='publish'===$raw_status?'Active':('draft'===$raw_status?'Draft':ucfirst((string)$raw_status));

            echo '<tr><td class="azsh-image-col">'.($edit?'<a href="'.esc_url($edit).'">'.$img.'</a>':$img).'</td>';
            echo '<td><strong>'.($edit?'<a href="'.esc_url($edit).'">'.esc_html($name).'</a>':esc_html($name)).'</strong><br>'.($edit?'<a href="'.esc_url($edit).'"><code>'.esc_html($product->get_sku('edit')).'</code></a>':'<code>'.esc_html($product->get_sku('edit')).'</code>').'</td>';
            echo '<td class="azsh-stock-col">';
            if($product->managing_stock()) echo '<div class="azsh-stock-editor"><input type="number" step="1" class="small-text azsh-stock-input" value="'.esc_attr($qd).'" data-original="'.esc_attr($qd).'"><button type="button" class="button azsh-update-stock" data-product="'.esc_attr($product->get_id()).'" data-sku="'.esc_attr($product->get_sku('edit')).'">Update</button></div>';
            else echo '<strong>'.$qd.'</strong>';
            $price_raw=$product->get_price('edit');
            $price=is_numeric($price_raw)?(float)$price_raw:0;
            $value=(!is_null($qty) && $qty>0)?$price*(float)$qty:0;
            echo '</td><td class="azsh-price">'.($price_raw!==''?wp_kses_post(wc_price($price)):'—').'</td>';
            echo '<td class="azsh-total-value-col" '.('value'===$sort?'':'hidden').'>'.wp_kses_post(wc_price($value)).'</td><td></td>';
            echo '<td>'.esc_html($label).'</td><td class="azsh-actions">';

            // Exact parent ID is used; no title matching, so similarly named products cannot leak in.
            if($product->is_type('variation')) {
                echo '<button type="button" class="button azsh-all-skus" data-parent="'.esc_attr($parent_id).'">All SKUs</button> ';
                echo '<button type="button" class="button azsh-view-history" data-variation="'.esc_attr($product->get_id()).'">View History</button>';
            } else {
                $h=add_query_arg(array('page'=>'azula-stock-history','tab'=>'history','sku'=>$product->get_sku('edit')),admin_url('admin.php'));
                echo '<a class="button" href="'.esc_url($h).'">View History</a>';
            }
            echo '</td><td><span class="azsh-product-status azsh-status-'.esc_attr($raw_status).'">'.esc_html($display_status).'</span></td></tr>';
        }
        echo '</tbody></table>';

        $pages=(int)ceil($total/$per);
        if($pages>1) {
            $base=add_query_arg(array('page'=>'azula-stock-history','tab'=>'lookup','s'=>$search,'stock'=>$stock_filter,'category'=>$category,'product_status'=>$product_status,'sort'=>$sort,'order'=>$order,'paged'=>'%#%'),admin_url('admin.php'));
            echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>$base,'format'=>'','current'=>$paged,'total'=>$pages))).'</div></div>';
        }
        echo '<div id="azsh-modal" class="azsh-modal" hidden><div class="azsh-modal-card"><button type="button" class="azsh-close">×</button><h2>Stock History</h2><div class="azsh-modal-body"></div></div></div>';
        echo '<div id="azsh-skus-modal" class="azsh-modal" hidden><div class="azsh-modal-card azsh-skus-card"><button type="button" class="azsh-skus-close">×</button><h2 class="azsh-skus-title">All SKUs</h2><div class="azsh-skus-body"></div></div></div>';
    }


    private static function audit_norm( $text ) {
        $text = strtolower( remove_accents( wp_strip_all_tags( (string) $text ) ) );
        $text = str_replace( array('&','–','—','_','/'), ' ', $text );
        return trim( preg_replace('/\s+/', ' ', $text) );
    }

    private static function audit_has_any( $haystack, $needles ) {
        $haystack = ' ' . self::audit_norm($haystack) . ' ';
        foreach ( $needles as $n ) {
            $n = self::audit_norm($n);
            if ( '' !== $n && false !== strpos($haystack, $n) ) return true;
        }
        return false;
    }

    private static function audit_find_term( $candidates ) {
        foreach ( $candidates as $candidate ) {
            if ( is_int($candidate) || ctype_digit((string)$candidate) ) {
                $t=get_term((int)$candidate,'product_cat');
                if($t && !is_wp_error($t)) return $t;
                continue;
            }
            $slug=sanitize_title((string)$candidate);
            $t=get_term_by('slug',$slug,'product_cat');
            if($t && !is_wp_error($t)) return $t;
        }
        return null;
    }

    private static function audit_product( $product_id ) {
        $product=wc_get_product($product_id);
        if(!$product || $product->is_type('variation')) return null;

        $cat_terms=wp_get_post_terms($product_id,'product_cat');
        if(is_wp_error($cat_terms)) $cat_terms=array();
        $tag_terms=wp_get_post_terms($product_id,'product_tag',array('fields'=>'names'));
        if(is_wp_error($tag_terms)) $tag_terms=array();

        $cat_names=array(); $cat_ids=array(); $cat_slugs=array();
        foreach($cat_terms as $t){$cat_names[]=$t->name;$cat_ids[]=(int)$t->term_id;$cat_slugs[]=$t->slug;}
        $evidence=$product->get_name().' '.implode(' ',$tag_terms).' '.implode(' ',$cat_names).' '.implode(' ',$cat_slugs);

        $brands=array(
            'Alphalete'=>array('alphalete'),
            'AYBL'=>array('aybl'),
            'Alo'=>array('alo','alo yoga','alo-yoga'),
            'BO & TEE'=>array('bo & tee','bo tee','botee'),
            'Dfyne'=>array('dfyne'),
            'Gymshark'=>array('gymshark'),
            'Oace'=>array('oace'),
            'Oner Active'=>array('oner active','oner-active'),
            'Teveo'=>array('teveo'),
            'YoungLA'=>array('youngla','young la'),
        );
        $brand='';
        foreach($brands as $label=>$signals){ if(self::audit_has_any($evidence,$signals)){ $brand=$label; break; } }

        $women=self::audit_has_any($evidence,array(' women ',' womens ',' women\'s ','female','ladies','alo women','gymshark womens','gymshark women','youngla women'));
        $men=self::audit_has_any($evidence,array(' men ',' mens ',' men\'s ','male','alo men','gymshark mens','gymshark men','youngla men'));
        // Exact known gender category IDs are strong evidence.
        if(in_array(22,$cat_ids,true)) $women=true;
        if(in_array(54,$cat_ids,true)) $men=true;
        $gender=$women&&!$men?'Women':($men&&!$women?'Men':($women&&$men?'Unisex':'Unclassified'));

        $type='';
        // Existing Woo merchandise collection membership is stronger than loose tags/keywords.
        $category_evidence=' '.implode(' ',$cat_names).' '.implode(' ',$cat_slugs).' ';
        $category_type_rules=array(
            'Pants'=>array("men's pants",'mens pants','men pants',"women's pants",'womens pants','women pants','mens-pants','men-pants','womens-pants','women-pants'),
            'Shorts'=>array("men's shorts",'mens shorts','men shorts',"women's shorts",'womens shorts','women shorts','mens-shorts','men-shorts','womens-shorts','women-shorts'),
            'Leggings'=>array("women's leggings",'womens leggings','women leggings','womens-leggings','women-leggings','leggings'),
            'Bras'=>array("women's bras",'womens bras','women bras','sports bras','sports-bras','womens-bras','women-bras'),
            'Hoodies & Jackets'=>array("men's hoodies & jackets",'mens hoodies jackets','men hoodies jackets',"women's jackets & hoodies",'womens jackets hoodies','womens-jackets-hoodies','mens-hoodies-jackets','men-hoodies-jackets'),
            'Footwear'=>array("men's footwear",'mens footwear','men footwear',"women's footwear",'womens footwear','women footwear','mens-footwear','men-footwear','womens-footwear','women-footwear'),
            'Tops'=>array("women's tops",'womens tops','women tops','womens-tops','women-tops',"men's shirts",'mens shirts','men shirts','mens-shirts','men-shirts','mens-tops','men-tops'),
        );
        foreach($category_type_rules as $label=>$signals){ if(self::audit_has_any($category_evidence,$signals)){ $type=$label; break; } }
        if(!$type){
            $type_rules=array(
                'Bras'=>array('sports bra','sport bra','sports bras','sport bras',' bra ',' bras ','bralette'),
                'Leggings'=>array('legging','leggings','tights'),
                'Pants'=>array('pants','jogger','joggers','trouser','trousers','cargo'),
                'Hoodies & Jackets'=>array('hoodie','hoodies','jacket','jackets','pullover','sweatshirt','sweatshirts','crew neck','crewneck','zip'),
                'Footwear'=>array('footwear','shoe','shoes','sneaker','sneakers','trainer','trainers'),
                'Tops'=>array('tank top','tank','t-shirt','t shirt','tee','top','tops','crop top','long sleeve','shirt','shirts'),
                'Shorts'=>array('shorts',' short '),
            );
            foreach($type_rules as $label=>$signals){ if(self::audit_has_any($evidence,$signals)){ $type=$label; break; } }
        }

        $expected=array();
        $add_expected=function($term,$reason) use (&$expected,$cat_ids){
            if($term && !in_array((int)$term->term_id,$cat_ids,true)) $expected[(int)$term->term_id]=array('term'=>$term,'reason'=>$reason);
        };

        // Confirmed exact gender category IDs.
        if('Women'===$gender) $add_expected(self::audit_find_term(array(22)),'Gender');
        elseif('Men'===$gender) $add_expected(self::audit_find_term(array(54)),'Gender');

        // Product-type collections: use exact slugs only. Women's Tops is confirmed from the live storefront.
        // Other slugs are only used if that exact Woo term exists; otherwise the audit does not invent a term.
        if($type && in_array($gender,array('Women','Men'),true)){
            $map=array(
                'Women'=>array(
                    'Tops'=>array('womens-tops'),
                    'Bras'=>array('sports-bras','womens-bras','bras'),
                    'Leggings'=>array('womens-leggings','leggings'),
                    'Shorts'=>array('womens-shorts','shorts'),
                    'Pants'=>array('womens-pants','pants'),
                    'Hoodies & Jackets'=>array('womens-jackets-hoodies','womens-hoodies-jackets'),
                    'Footwear'=>array('womens-footwear','footwear'),
                ),
                'Men'=>array(
                    'Tops'=>array('mens-shirts','men-shirts','mens-tops','men-tops','shirts'),
                    'Shorts'=>array('mens-shorts','men-shorts'),
                    'Pants'=>array('mens-pants','men-pants'),
                    'Hoodies & Jackets'=>array('mens-hoodies-jackets','men-hoodies-jackets'),
                    'Footwear'=>array('mens-footwear','men-footwear'),
                )
            );
            if(isset($map[$gender][$type])) $add_expected(self::audit_find_term($map[$gender][$type]),'Product type');
        }

        // Confirmed brand category IDs from prior Azula work.
        $brand_ids=array('Alphalete'=>83,'AYBL'=>49,'Alo'=>104,'BO & TEE'=>58,'Dfyne'=>35,'Gymshark'=>62,'Oace'=>110,'Oner Active'=>24,'Teveo'=>40,'YoungLA'=>79);
        if(isset($brand_ids[$brand])) $add_expected(self::audit_find_term(array($brand_ids[$brand])),'Brand');

        // Confirmed gender-specific brand category IDs.
        $brand_gender_ids=array(
            'Alo'=>array('Men'=>327,'Women'=>328),
            'Gymshark'=>array('Men'=>306,'Women'=>312),
        );
        if(isset($brand_gender_ids[$brand][$gender])) $add_expected(self::audit_find_term(array($brand_gender_ids[$brand][$gender])),'Brand + gender');
        // YoungLA gender-specific categories exist in navigation, but their exact IDs were not confirmed.
        // Resolve only exact existing slugs; never fuzzy-match names.
        if('YoungLA'===$brand && in_array($gender,array('Women','Men'),true)){
            $slug='Women'===$gender?'youngla-women':'youngla-men';
            $add_expected(self::audit_find_term(array($slug)),'Brand + gender');
        }

        // Current stock total across variations, or simple-product stock.
        $stock_qty=0;
        if($product->is_type('variable')){
            foreach($product->get_children() as $vid){
                $v=wc_get_product($vid);
                if($v){
                    $q=$v->get_stock_quantity('edit');
                    if(null!==$q && $q>0) $stock_qty+=(float)$q;
                }
            }
        } else {
            $q=$product->get_stock_quantity('edit');
            if(null!==$q && $q>0) $stock_qty=(float)$q;
        }
        $image_id=$product->get_image_id();
        $image=$image_id?wp_get_attachment_image_url($image_id,'thumbnail'):wc_placeholder_img_src('thumbnail');
        $status=get_post_status($product_id);
        return array(
            'product'=>$product,'categories'=>$cat_terms,'category_ids'=>$cat_ids,'tags'=>$tag_terms,
            'brand'=>$brand?:'Unclassified','gender'=>$gender,'type'=>$type?:'Unclassified','missing'=>$expected,'stock_qty'=>$stock_qty,'image'=>$image,'status'=>$status
        );
    }

    public static function category_audit_page() {
        $search=isset($_GET['s'])?sanitize_text_field(wp_unslash($_GET['s'])):'';
        $show=isset($_GET['show'])?sanitize_key(wp_unslash($_GET['show'])):'issues';
        if(!in_array($show,array('issues','all'),true))$show='issues';
        $product_status=isset($_GET['product_status'])?sanitize_key(wp_unslash($_GET['product_status'])):'active';
        $category=isset($_GET['category'])?absint($_GET['category']):0;
        if(!in_array($product_status,array('','active','draft'),true))$product_status='active';
        $sort=isset($_GET['sort'])?sanitize_key(wp_unslash($_GET['sort'])):'stock';
        $order=isset($_GET['order'])&&'asc'===strtolower(sanitize_key(wp_unslash($_GET['order'])))?'asc':'desc';
        $paged=max(1,isset($_GET['paged'])?absint($_GET['paged']):1); $per=50;

        $statuses=array('publish','draft','private');
        if('active'===$product_status)$statuses=array('publish');
        elseif('draft'===$product_status)$statuses=array('draft');

        $qargs=array('post_type'=>'product','post_status'=>$statuses,'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC');
        if($search!=='') $qargs['s']=$search;
        if($category) $qargs['tax_query']=array(array('taxonomy'=>'product_cat','field'=>'term_id','terms'=>array($category),'include_children'=>false));
        $ids=get_posts($qargs); $audits=array();
        foreach($ids as $id){
            $audit=self::audit_product($id); if(!$audit)continue;
            if('issues'===$show && empty($audit['missing']))continue;
            $audits[]=$audit;
        }
        if('stock'===$sort){
            usort($audits,function($x,$y)use($order){
                $cmp=$x['stock_qty']<=>$y['stock_qty'];
                if(0===$cmp)$cmp=strcasecmp($x['product']->get_name(),$y['product']->get_name());
                return 'asc'===$order?$cmp:-$cmp;
            });
        }
        $total=count($audits); $slice=array_slice($audits,($paged-1)*$per,$per);
        $allcats=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'orderby'=>'name','order'=>'ASC')); if(is_wp_error($allcats))$allcats=array();

        echo '<h2>Category Audit</h2>';
        echo '<p><strong>Review only.</strong> The scanner never changes categories automatically. A category changes only when you click its Add or Remove action.</p>';
        echo '<form method="get" class="azsh-audit-filters"><input type="hidden" name="page" value="azula-stock-history"><input type="hidden" name="tab" value="category-audit">';
        echo '<input name="s" class="azsh-search" placeholder="Search product name" value="'.esc_attr($search).'"> ';
        echo '<select name="show"><option value="issues" '.selected($show,'issues',false).'>Issues only</option><option value="all" '.selected($show,'all',false).'>All products</option></select> ';
        echo '<select name="category"><option value="0">All Categories</option>';
        foreach($allcats as $t) echo '<option value="'.esc_attr($t->term_id).'" '.selected($category,(int)$t->term_id,false).'>'.esc_html($t->name).'</option>';
        echo '</select> ';
        echo '<select name="product_status"><option value="" '.selected($product_status,'',false).'>All Statuses</option><option value="active" '.selected($product_status,'active',false).'>Active</option><option value="draft" '.selected($product_status,'draft',false).'>Draft</option></select> ';
        echo '<button class="button button-primary">Scan</button> <a class="button" href="'.esc_url(admin_url('admin.php?page=azula-stock-history&tab=category-audit')).'">Clear</a></form>';
        echo '<p><strong>'.esc_html(number_format_i18n($total)).'</strong> product(s) in this view.</p>';

        $stock_next=('stock'===$sort && 'desc'===$order)?'asc':'desc';
        $stock_url=add_query_arg(array('page'=>'azula-stock-history','tab'=>'category-audit','s'=>$search,'show'=>$show,'category'=>$category,'product_status'=>$product_status,'sort'=>'stock','order'=>$stock_next),admin_url('admin.php'));

        echo '<table class="widefat striped azsh-audit-table"><thead><tr><th>Photo</th><th>Product</th><th>Detected</th><th><a href="'.esc_url($stock_url).'">Current Stock'.('stock'===$sort?('asc'===$order?' ↑':' ↓'):'').'</a></th><th>Current Categories</th><th>Missing / Suggested</th><th>Manual Category Manager</th><th>Product Status</th></tr></thead><tbody>';
        if(!$slice) echo '<tr><td colspan="8">No matching products.</td></tr>';
        foreach($slice as $x){
            $pr=$x['product']; $pid=$pr->get_id(); $edit=get_edit_post_link($pid,'raw');
            echo '<tr data-product="'.esc_attr($pid).'">';
            echo '<td><img src="'.esc_url($x['image']).'" alt="" style="width:54px;height:68px;object-fit:cover;border-radius:3px"></td>';
            echo '<td><strong><a href="'.esc_url($edit).'">'.esc_html($pr->get_name()).'</a></strong><br><code>ID '.esc_html($pid).'</code></td>';
            echo '<td>Gender: <strong>'.esc_html($x['gender']).'</strong><br>Type: <strong>'.esc_html($x['type']).'</strong><br>Brand: <strong>'.esc_html($x['brand']).'</strong></td>';
            echo '<td><strong>'.esc_html(self::format_qty($x['stock_qty'])).'</strong></td>';
            echo '<td class="azsh-current-cats">';
            if(!$x['categories']) echo '—';
            foreach($x['categories'] as $t) echo '<span class="azsh-cat-chip" data-term="'.esc_attr($t->term_id).'">'.esc_html($t->name).'</span> ';
            echo '</td><td class="azsh-missing-cats">';
            if(!$x['missing']) echo '<span class="azsh-ok">✓ No missing category detected</span>';
            foreach($x['missing'] as $m){
                $t=$m['term'];
                echo '<div class="azsh-suggestion"><strong>⚠ '.esc_html($t->name).'</strong> <small>('.esc_html($m['reason']).')</small> <button type="button" class="button button-small azsh-cat-action" data-mode="add" data-term="'.esc_attr($t->term_id).'" data-term-name="'.esc_attr($t->name).'">Add</button></div>';
            }
            echo '</td><td><select class="azsh-category-select"><option value="">Choose category…</option>';
            foreach($allcats as $t) echo '<option value="'.esc_attr($t->term_id).'">'.esc_html($t->name).'</option>';
            echo '</select> <button type="button" class="button azsh-cat-manual" data-mode="add">Add</button> <button type="button" class="button azsh-cat-manual" data-mode="remove">Remove</button></td>';
            echo '<td><strong>'.('publish'===$x['status']?'Active':('draft'===$x['status']?'Draft':ucfirst($x['status']))).'</strong></td></tr>';
        }
        echo '</tbody></table>';

        $pages=(int)ceil($total/$per);
        if($pages>1){
            $base=add_query_arg(array('page'=>'azula-stock-history','tab'=>'category-audit','s'=>$search,'show'=>$show,'category'=>$category,'product_status'=>$product_status,'sort'=>$sort,'order'=>$order,'paged'=>'%#%'),admin_url('admin.php'));
            echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>$base,'format'=>'','current'=>$paged,'total'=>$pages))).'</div></div>';
        }
        echo '<p class="description">Detection uses current Woo categories, product tags and product names. Exact known Woo category IDs/slugs are used for suggestions; unresolved categories are never invented.</p>';
    }

    public static function ajax_category_change() {
        check_ajax_referer('azsh_history','nonce');
        if(!current_user_can('manage_woocommerce')) wp_send_json_error(array('message'=>'No permission.'),403);
        $product_id=isset($_POST['product_id'])?absint($_POST['product_id']):0;
        $term_id=isset($_POST['term_id'])?absint($_POST['term_id']):0;
        $mode=isset($_POST['mode'])?sanitize_key(wp_unslash($_POST['mode'])):'';
        $product=$product_id?wc_get_product($product_id):false;
        $term=$term_id?get_term($term_id,'product_cat'):false;
        if(!$product || $product->is_type('variation') || !$term || is_wp_error($term)) wp_send_json_error(array('message'=>'Product or category not found.'),404);

        if('add'===$mode){
            $r=wp_set_object_terms($product_id,array($term_id),'product_cat',true);
            if(is_wp_error($r)) wp_send_json_error(array('message'=>$r->get_error_message()),400);
            clean_post_cache($product_id);
            wp_send_json_success(array('message'=>'Added to '.$term->name.'.','term_id'=>$term_id,'term_name'=>$term->name,'mode'=>'add'));
        }
        if('remove'===$mode){
            $r=wp_remove_object_terms($product_id,array($term_id),'product_cat');
            if(is_wp_error($r)) wp_send_json_error(array('message'=>$r->get_error_message()),400);
            clean_post_cache($product_id);
            wp_send_json_success(array('message'=>'Removed from '.$term->name.'.','term_id'=>$term_id,'term_name'=>$term->name,'mode'=>'remove'));
        }
        wp_send_json_error(array('message'=>'Invalid action.'),400);
    }

    public static function ajax_product_skus() {
        check_ajax_referer('azsh_history','nonce');
        if(!current_user_can('manage_woocommerce')) wp_send_json_error(array('message'=>'No permission.'),403);
        $parent_id=isset($_POST['parent_id'])?absint($_POST['parent_id']):0;
        $parent=$parent_id?wc_get_product($parent_id):false;
        if(!$parent || !$parent->is_type('variable')) wp_send_json_error(array('message'=>'Variable product not found.'),404);

        $items=array();
        foreach($parent->get_children() as $variation_id) {
            $v=wc_get_product($variation_id); if(!$v) continue;
            $imgid=$v->get_image_id(); if(!$imgid) $imgid=$parent->get_image_id();
            $img=$imgid?wp_get_attachment_image($imgid,array(48,60),false,array('class'=>'azsh-thumb')):wc_placeholder_img(array(48,60));
            $qty=$v->get_stock_quantity('edit');
            $items[]=array(
                'name'=>$v->get_name(),
                'sku'=>$v->get_sku('edit'),
                'stock'=>is_null($qty)?'—':self::format_qty($qty),
                'image'=>$img,
                'variation_id'=>$variation_id
            );
        }
        wp_send_json_success(array('title'=>$parent->get_name(),'items'=>$items));
    }

    public static function export_inventory_csv() {
        if(!current_user_can('manage_woocommerce')) wp_die('No permission.');
        check_admin_referer('azsh_export_inventory');
        global $wpdb;

        $search=isset($_GET['s'])?sanitize_text_field(wp_unslash($_GET['s'])):'';
        $stock=isset($_GET['stock'])?sanitize_key(wp_unslash($_GET['stock'])):'';
        $category=isset($_GET['category'])?absint($_GET['category']):0;
        $status=isset($_GET['product_status'])?sanitize_key(wp_unslash($_GET['product_status'])):'';
        $sort=isset($_GET['sort'])?sanitize_key(wp_unslash($_GET['sort'])):'stock';
        $order=(isset($_GET['order'])&&'asc'===sanitize_key(wp_unslash($_GET['order'])))?'asc':'desc';

        $lookup=$wpdb->wc_product_meta_lookup; $posts=$wpdb->posts;
        $where=array("p.post_type IN ('product','product_variation')","p.post_status NOT IN ('trash','auto-draft')","NOT (p.post_type='product' AND EXISTS (SELECT 1 FROM {$posts} vx WHERE vx.post_parent=p.ID AND vx.post_type='product_variation' AND vx.post_status NOT IN ('trash','auto-draft')))"); $args=array();
        if($search!==''){ $like='%'.$wpdb->esc_like($search).'%'; $where[]='(l.sku LIKE %s OR p.post_title LIKE %s OR parent.post_title LIKE %s)'; array_push($args,$like,$like,$like); }
        if($category){
            $ids=get_term_children($category,'product_cat'); if(is_wp_error($ids))$ids=array(); $ids[]=$category; $ids=array_values(array_unique(array_map('absint',$ids)));
            $ph=implode(',',array_fill(0,count($ids),'%d'));
            $where[]="EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=(CASE WHEN p.post_type='product_variation' THEN p.post_parent ELSE p.ID END) AND tt.taxonomy='product_cat' AND tt.term_id IN ({$ph}))";
            $args=array_merge($args,$ids);
        }
        if('in'===$stock)$where[]='l.stock_quantity > 0'; elseif('out'===$stock)$where[]='(l.stock_quantity IS NULL OR l.stock_quantity <= 0)'; elseif('low'===$stock)$where[]='l.stock_quantity > 0 AND l.stock_quantity <= 2';
        if('active'===$status)$where[]="(CASE WHEN p.post_type='product_variation' THEN parent.post_status ELSE p.post_status END)='publish'";
        elseif('draft'===$status)$where[]="(CASE WHEN p.post_type='product_variation' THEN parent.post_status ELSE p.post_status END)='draft'";
        $dir='asc'===$order?'ASC':'DESC';
        if('product'===$sort)$ord="COALESCE(NULLIF(parent.post_title,''),p.post_title) {$dir},l.sku {$dir}";
        elseif('status'===$sort)$ord="(CASE WHEN p.post_type='product_variation' THEN parent.post_status ELSE p.post_status END) {$dir},l.sku ASC";
        elseif('price'===$sort)$ord="(l.min_price IS NULL) ASC,l.min_price {$dir},l.product_id DESC";
        elseif('value'===$sort)$ord="((COALESCE(l.stock_quantity,0))*(COALESCE(l.min_price,0))) {$dir},l.product_id DESC";
        else $ord="(l.stock_quantity IS NULL) ASC,l.stock_quantity {$dir},l.product_id DESC";
        $sql="SELECT l.product_id FROM {$lookup} l INNER JOIN {$posts} p ON p.ID=l.product_id LEFT JOIN {$posts} parent ON parent.ID=p.post_parent WHERE ".implode(' AND ',$where)." ORDER BY {$ord}";
        $ids=$wpdb->get_col($args?$wpdb->prepare($sql,$args):$sql);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=azula-inventory-'.gmdate('Y-m-d-His').'.csv');
        $fh=fopen('php://output','w');
        // UTF-8 BOM keeps Excel-friendly text rendering.
        fwrite($fh,"\xEF\xBB\xBF");
        fputcsv($fh,array('Product','SKU','Current Stock','Stock Status','Product Status','Price','Total Value'));
        foreach($ids as $id){
            $p=wc_get_product((int)$id); if(!$p)continue;
            $qty=$p->get_stock_quantity('edit'); $price_raw=$p->get_price('edit'); $price=is_numeric($price_raw)?(float)$price_raw:0;
            $parent_id=$p->is_type('variation')?$p->get_parent_id():$p->get_id();
            $ps=get_post_status($parent_id); $ps='publish'===$ps?'Active':('draft'===$ps?'Draft':ucfirst((string)$ps));
            $ss=$p->get_stock_status(); $ss='instock'===$ss?'In stock':('onbackorder'===$ss?'On backorder':'Out of stock');
            if(!is_null($qty)&&$qty>0&&$qty<=2)$ss='Low stock';
            fputcsv($fh,array($p->get_name(),$p->get_sku('edit'),is_null($qty)?'':self::format_qty($qty),$ss,$ps,$price_raw===''?'':wc_format_decimal($price,2),(!is_null($qty)&&$qty>0)?wc_format_decimal($price*(float)$qty,2):'0.00'));
        }
        fclose($fh); exit;
    }

    public static function ajax_update_stock() {
        check_ajax_referer('azsh_history','nonce');
        if(!current_user_can('manage_woocommerce')) wp_send_json_error(array('message'=>'No permission.'),403);
        $id=isset($_POST['product_id'])?absint($_POST['product_id']):0;
        $raw=isset($_POST['new_stock'])?wc_clean(wp_unslash($_POST['new_stock'])):'';
        if(!$id||$raw===''||!is_numeric($raw)) wp_send_json_error(array('message'=>'Invalid stock quantity.'),400);
        $p=wc_get_product($id);
        if(!$p||!$p->managing_stock()) wp_send_json_error(array('message'=>'This SKU does not manage stock quantity.'),400);
        $old=$p->get_stock_quantity('edit'); $new=wc_stock_amount($raw);
        if((float)$old===(float)$new) wp_send_json_success(array('message'=>'No change.','stock'=>self::format_qty($new)));
        // Deliberate manager action. WooCommerce performs the stock write; normal stock hooks
        // fire so the independent history logger records the authenticated admin user.
        $result=wc_update_product_stock($p,$new,'set',true);
        if(is_wp_error($result)) wp_send_json_error(array('message'=>$result->get_error_message()),500);
        wp_send_json_success(array('message'=>'Stock updated from '.self::format_qty($old).' to '.self::format_qty($result).'.','stock'=>self::format_qty($result)));
    }

    private static function filters() {
        return array(
            'sku'       => isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( $_GET['sku'] ) ) : '',
            'product'   => isset( $_GET['product'] ) ? sanitize_text_field( wp_unslash( $_GET['product'] ) ) : '',
            'date'      => isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '',
            'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
            'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
            'direction' => isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : '',
            'source'    => isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '',
            'reference' => isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '',
        );
    }

    private static function valid_date( $date ) {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
    }

    private static function local_day_to_gmt_bounds( $date ) {
        try {
            $tz    = wp_timezone();
            $start = new DateTimeImmutable( $date . ' 00:00:00', $tz );
            $end   = new DateTimeImmutable( $date . ' 23:59:59', $tz );
            return array(
                $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
                $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
            );
        } catch ( Throwable $e ) {
            return array( '', '' );
        }
    }

    private static function local_boundary_to_gmt( $date, $end = false ) {
        if ( ! self::valid_date( $date ) ) { return ''; }
        $bounds = self::local_day_to_gmt_bounds( $date );
        return $end ? $bounds[1] : $bounds[0];
    }

    private static function query( $variation_id = 0, $limit = 50, $offset = 0, $count = false ) {
        global $wpdb;

        $table = AZSH_DB::table();
        $where = array( '1=1' );
        $args  = array();

        if ( $variation_id ) {
            $where[] = 'variation_id = %d';
            $args[]  = $variation_id;
        } else {
            $f = self::filters();

            if ( '' !== $f['sku'] ) {
                $where[] = 'sku LIKE %s';
                $args[]  = '%' . $wpdb->esc_like( $f['sku'] ) . '%';
            }

            if ( '' !== $f['product'] ) {
                $where[] = 'product_name LIKE %s';
                $args[]  = '%' . $wpdb->esc_like( $f['product'] ) . '%';
            }

            // A specific date takes precedence over the range.
            if ( self::valid_date( $f['date'] ) ) {
                list( $start, $end ) = self::local_day_to_gmt_bounds( $f['date'] );
                if ( $start && $end ) {
                    $where[] = 'created_gmt BETWEEN %s AND %s';
                    $args[]  = $start;
                    $args[]  = $end;
                }
            } else {
                $from = self::local_boundary_to_gmt( $f['date_from'], false );
                $to   = self::local_boundary_to_gmt( $f['date_to'], true );

                if ( $from ) {
                    $where[] = 'created_gmt >= %s';
                    $args[]  = $from;
                }
                if ( $to ) {
                    $where[] = 'created_gmt <= %s';
                    $args[]  = $to;
                }
            }

            if ( 'positive' === $f['direction'] ) { $where[] = 'stock_delta > 0'; }
            if ( 'negative' === $f['direction'] ) { $where[] = 'stock_delta < 0'; }

            if ( '' !== $f['source'] ) {
                $where[] = 'source LIKE %s';
                $args[]  = '%' . $wpdb->esc_like( $f['source'] ) . '%';
            }

            if ( '' !== $f['reference'] ) {
                $where[] = 'reference LIKE %s';
                $args[]  = '%' . $wpdb->esc_like( $f['reference'] ) . '%';
            }
        }

        $sql = ( $count ? 'SELECT COUNT(*)' : 'SELECT *' ) . " FROM {$table} WHERE " . implode( ' AND ', $where );

        if ( ! $count ) {
            $sql   .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
            $args[] = $limit;
            $args[] = $offset;
        }

        if ( $args ) {
            $sql = $wpdb->prepare( $sql, $args );
        }

        return $count ? (int) $wpdb->get_var( $sql ) : $wpdb->get_results( $sql );
    }

    private static function format_qty( $value ) {
        if ( null === $value || '' === $value ) { return '—'; }
        $number = (float) $value;
        return ( floor( $number ) == $number ) ? (string) (int) $number : rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' );
    }

    public static function page( $wrapper = true ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

        $per   = 50;
        $page  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
        $total = self::query( 0, $per, 0, true );
        $rows  = self::query( 0, $per, ( $page - 1 ) * $per, false );
        $f     = self::filters();

        if($wrapper){echo '<div class="wrap">';} echo '<h2>Stock History</h2>';
        echo '<p>Every recorded SKU stock movement. Newest changes appear first.</p>';
        echo '<form method="get" class="azsh-filters"><input type="hidden" name="page" value="azula-stock-history"><input type="hidden" name="tab" value="history">';
        echo '<input name="sku" placeholder="SKU" value="' . esc_attr( $f['sku'] ) . '"> ';
        echo '<input name="product" placeholder="Product name" value="' . esc_attr( $f['product'] ) . '"> ';
        echo '<label>Specific date <input type="date" name="date" value="' . esc_attr( $f['date'] ) . '"></label> ';
        echo '<label>From <input type="date" name="date_from" value="' . esc_attr( $f['date_from'] ) . '"></label> ';
        echo '<label>To <input type="date" name="date_to" value="' . esc_attr( $f['date_to'] ) . '"></label> ';
        echo '<select name="direction"><option value="">All changes</option><option value="positive" ' . selected( $f['direction'], 'positive', false ) . '>Positive only</option><option value="negative" ' . selected( $f['direction'], 'negative', false ) . '>Negative only</option></select> ';
        echo '<input name="source" placeholder="Source" value="' . esc_attr( $f['source'] ) . '"> ';
        echo '<input name="reference" placeholder="Order/reference" value="' . esc_attr( $f['reference'] ) . '"> ';
        echo '<button class="button button-primary">Filter</button> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=azula-stock-history&tab=history' ) ) . '">Clear</a></form>';

        echo '<p><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> movement(s) found.</p>';
        echo '<table class="widefat striped azsh-table"><thead><tr><th>Date/time</th><th>Product</th><th>SKU</th><th>IDs</th><th>Previous → New</th><th>Difference</th><th>Source</th><th>Reference</th></tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="8">No stock history found.</td></tr>';
        }

        foreach ( $rows as $r ) {
            $local = get_date_from_gmt( $r->created_gmt, 'Y-m-d H:i:s' );
            $delta = (float) $r->stock_delta;
            $delta_text = ( $delta > 0 ? '+' : '' ) . self::format_qty( $delta );
            $change = self::format_qty( $r->old_stock ) . ' → ' . self::format_qty( $r->new_stock );

            echo '<tr>';
            echo '<td>' . esc_html( $local ) . '</td>';
            echo '<td>' . esc_html( $r->product_name ) . '</td>';
            echo '<td><code>' . esc_html( $r->sku ) . '</code></td>';
            echo '<td>P ' . (int) $r->product_id . ( $r->variation_id ? ' / V ' . (int) $r->variation_id : '' ) . '</td>';
            echo '<td>' . esc_html( $change ) . '</td>';
            echo '<td><strong>' . esc_html( $delta_text ) . '</strong></td>';
            echo '<td>' . esc_html( $r->source ) . '</td>';
            echo '<td>' . esc_html( $r->reference ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $pages = (int) ceil( $total / $per );
        if ( $pages > 1 ) {
            $base_args = $_GET;
            unset( $base_args['paged'] );
            $base = add_query_arg( $base_args, admin_url( 'admin.php' ) );
            $base = add_query_arg( 'paged', '%#%', $base );
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
                paginate_links(
                    array(
                        'base'    => $base,
                        'format'  => '',
                        'current' => $page,
                        'total'   => $pages,
                    )
                )
            ) . '</div></div>';
        }

        echo '<div id="azsh-modal" class="azsh-modal" hidden><div class="azsh-modal-card"><button type="button" class="azsh-close">×</button><h2>Stock History</h2><div class="azsh-modal-body"></div></div></div>'; if($wrapper){echo '</div>';}
    }

    public static function ajax_history() {
        check_ajax_referer( 'azsh_history', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $id   = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
        $rows = self::query( $id, 100, 0, false );

        ob_start();
        echo '<table class="widefat striped"><thead><tr><th>Date/time</th><th>Change</th><th>Difference</th><th>Source</th><th>Reference</th></tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="5">No history yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            $d = (float) $r->stock_delta;
            echo '<tr>';
            echo '<td>' . esc_html( get_date_from_gmt( $r->created_gmt, 'Y-m-d H:i:s' ) ) . '</td>';
            echo '<td>' . esc_html( self::format_qty( $r->old_stock ) . ' → ' . self::format_qty( $r->new_stock ) ) . '</td>';
            echo '<td>' . esc_html( ( $d > 0 ? '+' : '' ) . self::format_qty( $d ) ) . '</td>';
            echo '<td>' . esc_html( $r->source ) . '</td>';
            echo '<td>' . esc_html( $r->reference ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        wp_send_json_success( array( 'html' => ob_get_clean() ) );
    }
}