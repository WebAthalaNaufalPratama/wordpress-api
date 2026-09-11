<?php
/**
 * Plugin Name: Headless MCP Abilities
 * Description: Mengekspos operasi pesanan WooCommerce ke agent AI lewat MCP (Abilities API + mcp-adapter).
 * Version:     0.1.0
 *
 * Dimuat sebagai mu-plugin, jadi jalan sebelum plugin biasa. Semua akses ke
 * WooCommerce sengaja ditaruh di dalam hook yang baru menyala setelah
 * plugins_loaded, sehingga WooCommerce sudah pasti tersedia saat dipanggil.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HEADLESS_MCP_CATEGORY = 'woocommerce-orders';

/** Ability yang didaftarkan sebagai tool langsung di default MCP server. */
const HEADLESS_MCP_ABILITIES = array(
	'woo/list-orders',
	'woo/get-order',
	'woo/complete-order',
	'woo/create-product',
);

/** Status yang boleh dipindah ke `completed`. Status lain ditolak. */
const HEADLESS_MCP_COMPLETABLE_STATUSES = array( 'processing', 'on-hold' );

/** Capability minimum. Role Shop Manager punya ini; Subscriber tidak. */
const HEADLESS_MCP_CAPABILITY = 'manage_woocommerce';

// ---------------------------------------------------------------------------
// Kategori
// ---------------------------------------------------------------------------

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			HEADLESS_MCP_CATEGORY,
			array(
				'label'       => 'Pesanan WooCommerce',
				'description' => 'Membaca dan mengelola pesanan toko.',
			)
		);
	}
);

// ---------------------------------------------------------------------------
// Ability
// ---------------------------------------------------------------------------

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$permission = static fn(): bool => current_user_can( HEADLESS_MCP_CAPABILITY );

		wp_register_ability(
			'woo/list-orders',
			array(
				'label'               => 'Daftar pesanan',
				'description'         => 'Mengambil daftar pesanan terbaru, bisa disaring per status. Gunakan status "processing" untuk pesanan yang belum dikirim.',
				'category'            => HEADLESS_MCP_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
							'default'     => 'any',
							'description' => 'Saring berdasarkan status. "any" = semua status.',
						),
						'limit'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 50,
							'default'     => 10,
							'description' => 'Jumlah maksimum pesanan yang dikembalikan.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'  => array( 'type' => 'integer' ),
						'orders' => array(
							'type'  => 'array',
							'items' => headless_mcp_order_schema(),
						),
					),
				),
				'execute_callback'    => 'headless_mcp_list_orders',
				'permission_callback' => $permission,
				'meta'                => array(
					'public'      => true,
					'mcp'         => array( 'type' => 'tool' ),
					'annotations' => array(
						'readOnlyHint' => true,
						'title'        => 'Daftar pesanan',
					),
				),
			)
		);

		wp_register_ability(
			'woo/get-order',
			array(
				'label'               => 'Detail pesanan',
				'description'         => 'Mengambil detail satu pesanan berdasarkan ID-nya, termasuk item dan pembeli.',
				'category'            => HEADLESS_MCP_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID pesanan (angka di kolom "id", sama dengan nomor pesanan di wp-admin).',
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => headless_mcp_order_schema(),
				'execute_callback'    => 'headless_mcp_get_order',
				'permission_callback' => $permission,
				'meta'                => array(
					'public'      => true,
					'mcp'         => array( 'type' => 'tool' ),
					'annotations' => array(
						'readOnlyHint' => true,
						'title'        => 'Detail pesanan',
					),
				),
			)
		);

		wp_register_ability(
			'woo/complete-order',
			array(
				'label'               => 'Selesaikan pesanan',
				'description'         => 'Menandai pesanan sebagai selesai (completed), artinya barang sudah dikirim ke pembeli. Hanya boleh untuk pesanan berstatus "processing" atau "on-hold". Pembeli akan menerima email pesanan selesai.',
				'category'            => HEADLESS_MCP_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID pesanan yang akan diselesaikan.',
						),
						'note'     => array(
							'type'        => 'string',
							'maxLength'   => 500,
							'description' => 'Catatan tambahan opsional, misalnya nomor resi.',
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'previous_status' => array( 'type' => 'string' ),
						'status'          => array( 'type' => 'string' ),
						'note'            => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'headless_mcp_complete_order',
				'permission_callback' => $permission,
				'meta'                => array(
					'public'      => true,
					'mcp'         => array( 'type' => 'tool' ),
					'annotations' => array(
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'title'           => 'Selesaikan pesanan',
					),
				),
			)
		);

		wp_register_ability(
			'woo/create-product',
			array(
				'label'               => 'Buat produk',
				'description'         => 'Membuat produk sederhana (simple product) baru di WooCommerce. Produk langsung tampil di storefront Next.js kalau status "publish". Harga dalam mata uang toko (IDR), tanpa pemisah ribuan.',
				'category'            => HEADLESS_MCP_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'              => array(
							'type'        => 'string',
							'minLength'   => 3,
							'maxLength'   => 200,
							'description' => 'Nama produk.',
						),
						'regular_price'     => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => 'Harga normal, mis. 95000.',
						),
						'sale_price'        => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => 'Harga diskon opsional. Harus lebih kecil dari regular_price.',
						),
						'description'       => array(
							'type'        => 'string',
							'maxLength'   => 5000,
							'description' => 'Deskripsi panjang. Boleh HTML sederhana (p, ul, li, strong).',
						),
						'short_description' => array(
							'type'        => 'string',
							'maxLength'   => 500,
							'description' => 'Ringkasan satu-dua kalimat, tampil di kartu produk.',
						),
						'sku'               => array(
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Kode SKU opsional. Harus unik.',
						),
						'stock_quantity'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Jumlah stok awal. Kalau diisi, manajemen stok otomatis aktif.',
						),
						'image_url'         => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'URL gambar utama (jpg/png/webp). Akan diunduh ke media library.',
						),
						'status'            => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'default'     => 'publish',
							'description' => '"publish" = langsung tampil di toko; "draft" = disimpan dulu.',
						),
					),
					'required'             => array( 'name', 'regular_price' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'name'       => array( 'type' => 'string' ),
						'slug'       => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'price'      => array( 'type' => 'string' ),
						'sku'        => array( 'type' => 'string' ),
						'stock'      => array( 'type' => array( 'integer', 'null' ) ),
						'image_set'  => array( 'type' => 'boolean' ),
						'admin_url'  => array( 'type' => 'string' ),
						'store_path' => array( 'type' => 'string', 'description' => 'Path di storefront Next.js, mis. /products/kaos-polos.' ),
					),
				),
				'execute_callback'    => 'headless_mcp_create_product',
				'permission_callback' => $permission,
				'meta'                => array(
					'public'      => true,
					'mcp'         => array( 'type' => 'tool' ),
					'annotations' => array(
						'readOnlyHint'    => false,
						'destructiveHint' => false,
						'idempotentHint'  => false,
						'title'           => 'Buat produk',
					),
				),
			)
		);
	}
);

// ---------------------------------------------------------------------------
// Daftarkan sebagai tool langsung di default server.
// Tanpa ini, ability hanya bisa dicapai lewat meta-tool execute-ability.
// ---------------------------------------------------------------------------

add_filter(
	'mcp_adapter_default_server_config',
	static function ( array $config ): array {
		$config['tools'] = array_values(
			array_unique( array_merge( $config['tools'] ?? array(), HEADLESS_MCP_ABILITIES ) )
		);
		return $config;
	}
);

// ---------------------------------------------------------------------------
// Callback
// ---------------------------------------------------------------------------

/**
 * @param array{status?: string, limit?: int} $input
 * @return array{count: int, orders: array<int, array<string, mixed>>}
 */
function headless_mcp_list_orders( array $input ): array {
	$status = $input['status'] ?? 'any';
	$limit  = (int) ( $input['limit'] ?? 10 );

	$orders = wc_get_orders(
		array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => 'any' === $status ? array_keys( wc_get_order_statuses() ) : 'wc-' . $status,
		)
	);

	$rows = array_map( 'headless_mcp_order_to_array', $orders );

	return array(
		'count'  => count( $rows ),
		'orders' => array_values( $rows ),
	);
}

/**
 * @param array{order_id: int} $input
 * @return array<string, mixed>|WP_Error
 */
function headless_mcp_get_order( array $input ) {
	$order = headless_mcp_find_order( (int) $input['order_id'] );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	return headless_mcp_order_to_array( $order );
}

/**
 * @param array{order_id: int, note?: string} $input
 * @return array{id: int, previous_status: string, status: string, note: string}|WP_Error
 */
function headless_mcp_complete_order( array $input ) {
	$order = headless_mcp_find_order( (int) $input['order_id'] );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$previous = $order->get_status();

	if ( 'completed' === $previous ) {
		return new WP_Error(
			'order_already_completed',
			sprintf( 'Pesanan #%d sudah berstatus selesai.', $order->get_id() )
		);
	}

	if ( ! in_array( $previous, HEADLESS_MCP_COMPLETABLE_STATUSES, true ) ) {
		return new WP_Error(
			'order_not_completable',
			sprintf(
				'Pesanan #%d berstatus "%s" dan tidak bisa diselesaikan. Hanya status %s yang boleh.',
				$order->get_id(),
				$previous,
				implode( ' / ', HEADLESS_MCP_COMPLETABLE_STATUSES )
			)
		);
	}

	$note = 'Diselesaikan oleh Hermes Agent lewat MCP.';
	if ( ! empty( $input['note'] ) ) {
		$note .= ' Catatan: ' . sanitize_text_field( $input['note'] );
	}

	// Argumen ketiga = manual_update: WooCommerce menandai perubahan ini
	// sebagai tindakan manusia/operator di riwayat pesanan, bukan sistem.
	$ok = $order->update_status( 'completed', $note, true );
	if ( ! $ok ) {
		return new WP_Error(
			'order_update_failed',
			sprintf( 'WooCommerce menolak perubahan status pesanan #%d.', $order->get_id() )
		);
	}

	return array(
		'id'              => $order->get_id(),
		'previous_status' => $previous,
		'status'          => $order->get_status(),
		'note'            => $note,
	);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function headless_mcp_create_product( array $input ) {
	$regular = (float) $input['regular_price'];
	$sale    = isset( $input['sale_price'] ) ? (float) $input['sale_price'] : null;

	if ( null !== $sale && $sale >= $regular ) {
		return new WP_Error( 'sale_price_invalid', 'sale_price harus lebih kecil dari regular_price.' );
	}

	$sku = isset( $input['sku'] ) ? wc_clean( $input['sku'] ) : '';
	if ( '' !== $sku && wc_get_product_id_by_sku( $sku ) ) {
		return new WP_Error( 'sku_exists', sprintf( 'SKU "%s" sudah dipakai produk lain.', $sku ) );
	}

	$product = new WC_Product_Simple();
	$product->set_name( sanitize_text_field( $input['name'] ) );
	$product->set_regular_price( (string) $regular );
	if ( null !== $sale ) {
		$product->set_sale_price( (string) $sale );
	}
	$product->set_description( wp_kses_post( $input['description'] ?? '' ) );
	$product->set_short_description( wp_kses_post( $input['short_description'] ?? '' ) );
	$product->set_status( ( $input['status'] ?? 'publish' ) === 'draft' ? 'draft' : 'publish' );

	if ( '' !== $sku ) {
		$product->set_sku( $sku );
	}

	if ( isset( $input['stock_quantity'] ) ) {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( (int) $input['stock_quantity'] );
		$product->set_stock_status( (int) $input['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
	}

	try {
		$product_id = $product->save();
	} catch ( WC_Data_Exception $e ) {
		return new WP_Error( 'product_save_failed', $e->getMessage() );
	}

	if ( ! $product_id ) {
		return new WP_Error( 'product_save_failed', 'WooCommerce tidak mengembalikan ID produk.' );
	}

	$image_set = false;
	if ( ! empty( $input['image_url'] ) ) {
		$image_set = headless_mcp_attach_image( $product, esc_url_raw( $input['image_url'] ) );
	}

	$product = wc_get_product( $product_id );

	return array(
		'id'         => $product_id,
		'name'       => $product->get_name(),
		'slug'       => $product->get_slug(),
		'status'     => $product->get_status(),
		'price'      => wp_strip_all_tags( wc_price( (float) $product->get_price() ) ),
		'sku'        => $product->get_sku(),
		'stock'      => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
		'image_set'  => $image_set,
		'admin_url'  => admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
		'store_path' => '/products/' . $product->get_slug(),
	);
}

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Unduh gambar dari URL ke media library dan pasang sebagai gambar utama.
 * Gagal unduh tidak membatalkan produk — produk sudah tersimpan, hanya
 * gambarnya yang kosong, dan itu dilaporkan lewat `image_set: false`.
 */
function headless_mcp_attach_image( WC_Product $product, string $url ): bool {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = media_sideload_image( $url, $product->get_id(), $product->get_name(), 'id' );
	if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) ) {
		return false;
	}

	$product->set_image_id( $attachment_id );
	$product->save();

	return true;
}

/**
 * @return WC_Order|WP_Error
 */
function headless_mcp_find_order( int $order_id ) {
	$order = wc_get_order( $order_id );

	// wc_get_order() juga mengembalikan refund (WC_Order_Refund); tolak selain pesanan asli.
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error(
			'order_not_found',
			sprintf( 'Pesanan #%d tidak ditemukan.', $order_id )
		);
	}

	return $order;
}

/**
 * Bentuk ringkas pesanan yang dikirim ke agent. Sengaja tanpa alamat lengkap
 * dan nomor telepon — agent tidak butuh itu untuk menyelesaikan pesanan.
 *
 * @return array<string, mixed>
 */
function headless_mcp_order_to_array( WC_Order $order ): array {
	$created = $order->get_date_created();

	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array(
			'name'     => $item->get_name(),
			'quantity' => (int) $item->get_quantity(),
			'total'    => (float) $item->get_total(),
		);
	}

	return array(
		'id'             => $order->get_id(),
		'status'         => $order->get_status(),
		'date_created'   => $created ? $created->date( 'c' ) : null,
		'customer_name'  => trim( $order->get_formatted_billing_full_name() ),
		'customer_email' => $order->get_billing_email(),
		'payment_method' => $order->get_payment_method_title(),
		'currency'       => $order->get_currency(),
		'total'          => (float) $order->get_total(),
		'items'          => $items,
	);
}

/**
 * Skema output pesanan, dipakai bersama oleh list-orders dan get-order.
 *
 * @return array<string, mixed>
 */
function headless_mcp_order_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'             => array( 'type' => 'integer' ),
			'status'         => array( 'type' => 'string' ),
			'date_created'   => array( 'type' => array( 'string', 'null' ) ),
			'customer_name'  => array( 'type' => 'string' ),
			'customer_email' => array( 'type' => 'string' ),
			'payment_method' => array( 'type' => 'string' ),
			'currency'       => array( 'type' => 'string' ),
			'total'          => array( 'type' => 'number' ),
			'items'          => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'quantity' => array( 'type' => 'integer' ),
						'total'    => array( 'type' => 'number' ),
					),
				),
			),
		),
	);
}
