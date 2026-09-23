<?php

namespace Pay4Payment\Admin;

/**
 * Shared plumbing for dismissible admin-notice actions: nonce-protected
 * admin-post URLs, request authorization, the redirect-back tail, and the
 * notice shell they render into. One definition of the security-sensitive
 * boilerplate and of the presentation for every notice with an action
 * (plan review, watchdog, whatever comes next). Shared code, not `Pro\` —
 * the free build's PlanReview uses it too.
 */
trait AdminPostActions {

	/**
	 * Nonce-protected admin-post URL for a notice action.
	 *
	 * @param string $action Action name (also the nonce context).
	 * @param array<string, string> $args Extra query args.
	 * @return string
	 */
	private function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge( array( 'action' => $action ), $args ),
				admin_url( 'admin-post.php' )
			),
			$action
		);
	}

	/**
	 * Authorize a notice action request: nonce + capability, dies on
	 * failure.
	 *
	 * @param string $action Action name (nonce context).
	 * @return void
	 */
	private function authorize_action( string $action ): void {
		check_admin_referer( $action );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'woocommerce-pay-for-payment' ) );
		}
	}

	/**
	 * Print the shared notice shell: heading + body paragraph, an optional
	 * detail list, the action row (primary button + secondary link) and an
	 * optional closing footnote. One definition of the markup and inline
	 * styles for every notice with an action, so styling fixes are made once.
	 *
	 * The two trailing parameters exist for the upgrade notice (#186), the
	 * first caller that is not a warning: it announces good news, so its shell
	 * is `notice-info`, and its closing line about Pro carries a link, which
	 * the `esc_html()`'d body cannot. Both default to what the warning notices
	 * already rendered, so those are untouched.
	 *
	 * @param string $css_class Notice-specific class on the wrapper div.
	 * @param string $heading Bolded lead-in sentence (plain text).
	 * @param string $body Body text after the heading (plain text).
	 * @param string $list_html Optional `<li>…</li>` rows, already escaped by the caller; '' for none.
	 * @param string $primary_url Primary action URL.
	 * @param string $primary_label Primary action label (plain text).
	 * @param string $secondary_url Secondary action URL.
	 * @param string $secondary_label Secondary action label (plain text).
	 * @param string $type WordPress notice type suffix — `warning`, `info`, `success`, `error`.
	 * @param string $footnote_html Optional closing paragraph, already escaped by the caller; '' for none.
	 * @return void
	 */
	private function render_action_notice( string $css_class, string $heading, string $body, string $list_html, string $primary_url, string $primary_label, string $secondary_url, string $secondary_label, string $type = 'warning', string $footnote_html = '' ): void {
		$list = '' !== $list_html
			? sprintf( '<ul style="margin: 0 4px 12px; padding-left: 20px; list-style: disc;">%s</ul>', wp_kses_post( $list_html ) )
			: '';

		$footnote = '' !== $footnote_html
			? sprintf( '<p style="margin: 0 4px 12px; color: #50575e;">%s</p>', wp_kses_post( $footnote_html ) )
			: '';

		printf(
			'<div class="notice notice-%s %s"><p style="margin: 10px 4px 12px; font-size: 14px; line-height: 1.6;"><strong>%s</strong> %s</p>%s<p style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px 16px; margin: 0 4px 12px;"><a class="button button-primary" href="%s">%s</a> <a href="%s">%s</a></p>%s</div>',
			esc_attr( $type ),
			esc_attr( $css_class ),
			esc_html( $heading ),
			esc_html( $body ),
			$list, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above (wp_kses_post over caller-escaped rows).
			esc_url( $primary_url ),
			esc_html( $primary_label ),
			esc_url( $secondary_url ),
			esc_html( $secondary_label ),
			$footnote // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above (wp_kses_post over caller-escaped markup).
		);
	}

	/**
	 * Redirect and end the request. Extracted so tests can stub the exit.
	 *
	 * The default destination is the screen the action was fired from, which
	 * is what a dismissal wants: the notice disappears and the merchant is
	 * left where they were. A caller that is not returning anyone anywhere —
	 * {@see OptinPageFallback}, rescuing a URL that no longer resolves — names
	 * the screen instead, rather than repeating `wp_safe_redirect()` and the
	 * stubbed exit alongside this one.
	 *
	 * @param string $url Destination, or '' for the referring screen.
	 * @return void
	 */
	protected function finish_request( string $url = '' ) {
		wp_safe_redirect( '' !== $url ? $url : ( wp_get_referer() ?: admin_url() ) );
		exit;
	}
}
