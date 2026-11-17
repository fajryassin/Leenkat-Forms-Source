<?php
/**
 * Shared behavior for WordPress administration list tables.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Shared behavior for WordPress administration list tables.
 */
abstract class ManagementTable extends \WP_List_Table {
	/**
	 * WordPress uses action/action2 by default; reserve action for the admin-post route.
	 *
	 * @param mixed $which Which for this operation.
	 */
	protected function bulk_actions( $which = '' ) {
		$actions = $this->get_bulk_actions();
		if ( ! $actions ) {
			return;
		}
		$suffix = 'bottom' === $which ? '2' : '';
		echo '<label for="lkf-bulk-' . esc_attr( $which ) . '" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'leenkat-forms' ) . '</label><select name="operation' . esc_attr( $suffix ) . '" id="lkf-bulk-' . esc_attr( $which ) . '"><option value="-1">' . esc_html__( 'Bulk actions', 'leenkat-forms' ) . '</option>';
		foreach ( $actions as $value => $caption ) {
			echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $caption ) . '</option>';
		}
		echo '</select><button class="button action" name="leenfo_apply" value="' . esc_attr( $which ) . '">' . esc_html__( 'Apply', 'leenkat-forms' ) . '</button>';
	}
	/**
	 * Render list controls at the requested table position.
	 *
	 * @param mixed $which Which for this operation.
	 */
	protected function display_tablenav( $which ) {
		// Core's additional bulk nonce is unnecessary: each list form has its own route nonce.
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		if ( $this->has_items() ) {
			echo '<div class="alignleft actions bulkactions">';
			$this->bulk_actions( $which );
			echo '</div>'; }
		$this->extra_tablenav( $which );
		$this->pagination( $which );
		echo '<br class="clear"></div>';
	}
	/**
	 * Return the management table CSS classes.
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'fixed', 'striped', 'table-view-list', 'lkf-list-table' ); }
	/**
	 * Leave the row toggle to the primary column markup.
	 *
	 * @param mixed  $item        Current row item.
	 * @param string $column_name Column being rendered.
	 * @param string $primary     Primary column name.
	 * @return string Empty row actions markup.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		return ''; }
	/**
	 * Render the management table empty state.
	 */
	public function no_items() {
		esc_html_e( 'No items found.', 'leenkat-forms' ); }
	/**
	 * Set table headers and pagination context.
	 *
	 * @param array  $sortable Sortable for this operation.
	 * @param string $primary  Primary for this operation.
	 */
	protected function configure( array $sortable, string $primary ): void {
		$columns               = $this->get_columns();
		$this->_column_headers = array( $columns, get_hidden_columns( $this->screen ), $sortable, $primary );
	}
	/**
	 * Resolve the saved page size for the current list.
	 *
	 * @param string $option Option for this operation.
	 * @return int Result for the requested operation.
	 */
	protected function perPage( string $option ): int {
		return max( 1, min( 100, $this->get_items_per_page( $option, 20 ) ) ); }
	/**
	 * Normalize the requested list sort direction.
	 *
	 * @return string ASC or DESC.
	 */
	protected function sortDirection(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		return strtolower( ManagementPage::text( $_GET, 'order' ) ) === 'asc' ? 'ASC' : 'DESC'; }
	/**
	 * Render the row selection checkbox.
	 *
	 * @param mixed $item Item for this operation.
	 */
	public function column_cb( $item ) {
		return '<input type="checkbox" name="item_ids[]" value="' . (int) $item->id . '" aria-label="' . esc_attr( sprintf( /* translators: %d: item ID. */ __( 'Select item #%d', 'leenkat-forms' ), $item->id ) ) . '">'; }
	/**
	 * Render the controls for selecting a list page.
	 *
	 * @param string $route   Route for this operation.
	 * @param string $nonce   Nonce for this operation.
	 * @param array  $context Context for the requested operation.
	 */
	public function pageForm( string $route, string $nonce, array $context = array() ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lkf-list-form">';
		wp_nonce_field( $nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( $route ) . '">';
		foreach ( array( 's', 'orderby', 'order' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
			$context[ $name ] = ManagementPage::text( $_GET, $name );
		}
		foreach ( $context as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		$this->display();
		echo '</form>';
	}
}
