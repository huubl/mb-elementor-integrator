<?php
trait MBEI_Post {
	public function get_group() {
		return 'post';
	}

	private function get_option_groups() {
		$groups = [];

		$fields = $this->get_fields_by_object_type( 'post' );
		$fields = array_diff_key( $fields, array_flip( ['mb-post-type', 'mb-taxonomy'] ) );

		foreach ( $fields as $post_type => $list ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				continue;
			}
			$options = [];
			foreach ( $list as $field ) {
				$options[ $field['id'] ] = $field['name'] ?: $field['id'];
			}
			$groups[] = [
				'label'   => $post_type_object->labels->singular_name,
				'options' => $options,
			];
		}

		return $groups;
	}

	private function handle_get_value() {
		return rwmb_meta( $this->get_settings( 'key' ) );
	}
}