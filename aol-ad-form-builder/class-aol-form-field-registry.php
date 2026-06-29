<?php
/**
 * Canonical field-type registry for the v2 Form Builder.
 *
 * Single source of truth for field types, properties, validation, and sanitization.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'AOL_Form_Field_Registry' ) ) :

class AOL_Form_Field_Registry {

	/**
	 * @return array<string, array>
	 */
	public static function get_types() {
		$registry = self::build_registry();
		/**
		 * Filter the form field type registry.
		 *
		 * @param array $registry Field type definitions.
		 */
		return apply_filters( 'aol_form_field_registry', $registry );
	}

	/**
	 * @param string $type Field type key.
	 * @return array
	 */
	public static function get_type( $type ) {
		$types = self::get_types();
		$type  = sanitize_key( (string) $type );
		return isset( $types[ $type ] ) ? $types[ $type ] : $types['text'];
	}

	/**
	 * @param string $type Field type key.
	 * @return string[]
	 */
	public static function get_properties_for_type( $type ) {
		$def = self::get_type( $type );
		return isset( $def['properties'] ) && is_array( $def['properties'] ) ? $def['properties'] : array();
	}

	/**
	 * Default field payload for the editor (schema instance).
	 *
	 * @param string $type Field type key.
	 * @return array
	 */
	public static function get_default_field( $type ) {
		$def  = self::get_type( $type );
		$type = isset( $def['type'] ) ? $def['type'] : sanitize_key( (string) $type );

		$field = array(
			'id'    => '',
			'type'  => $type,
			'label' => '',
		);

		if ( ! empty( $def['defaults'] ) && is_array( $def['defaults'] ) ) {
			$field = array_merge( $field, $def['defaults'] );
		}

		foreach ( self::get_properties_for_type( $type ) as $prop ) {
			if ( ! array_key_exists( $prop, $field ) ) {
				$field[ $prop ] = self::default_property_value( $prop );
			}
		}

		return $field;
	}

	/**
	 * Registry subset safe for wp_localize_script / JSON.
	 *
	 * @return array<string, array>
	 */
	public static function get_types_for_js() {
		$out = array();
		foreach ( self::get_types() as $key => $def ) {
			$out[ $key ] = array(
				'label'      => isset( $def['label'] ) ? $def['label'] : $key,
				'icon'       => isset( $def['icon'] ) ? $def['icon'] : 'dashicons-admin-generic',
				'properties' => isset( $def['properties'] ) ? $def['properties'] : array(),
				'defaults'   => isset( $def['defaults'] ) ? $def['defaults'] : array(),
				'rules'      => isset( $def['rules'] ) ? $def['rules'] : array(),
				'preview'    => isset( $def['preview'] ) ? $def['preview'] : 'input',
				'inputType'   => isset( $def['inputType'] ) ? $def['inputType'] : 'text',
				'choices'     => isset( $def['choices'] ) ? $def['choices'] : '',
				'elementKind' => isset( $def['elementKind'] ) ? $def['elementKind'] : 'input',
			);
		}
		return $out;
	}

	/**
	 * Normalize a schema field for the hidden JSON input.
	 *
	 * @param array $field Raw field from meta or editor.
	 * @return array|null Null when invalid.
	 */
	public static function normalize_schema_field( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$uid   = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
		$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		$type  = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';

		if ( $uid === '' || $label === '' ) {
			return null;
		}

		if ( ! array_key_exists( $type, self::get_types() ) ) {
			$type = 'text';
		}

		$out = array(
			'id'    => $uid,
			'type'  => $type,
			'label' => $label,
		);

		foreach ( self::get_properties_for_type( $type ) as $prop ) {
			if ( $prop === 'id' || $prop === 'label' || $prop === 'type' ) {
				continue;
			}
			$out[ $prop ] = self::sanitize_property( $prop, isset( $field[ $prop ] ) ? $field[ $prop ] : null, $type );
		}

		return $out;
	}

	/**
	 * Sanitize a field for post meta storage.
	 *
	 * @param array $field Schema field from the builder.
	 * @return array|null Meta value array, or null to skip the field.
	 */
	public static function sanitize_field( $field ) {
		$normalized = self::normalize_schema_field( $field );
		if ( null === $normalized ) {
			return null;
		}

		$type = $normalized['type'];
		$val  = array(
			'label' => $normalized['label'],
			'type'  => $type,
		);

		$props = self::get_properties_for_type( $type );
		foreach ( $props as $prop ) {
			if ( in_array( $prop, array( 'id', 'label', 'type' ), true ) ) {
				continue;
			}
			if ( ! array_key_exists( $prop, $normalized ) ) {
				continue;
			}
			$val[ $prop ] = $normalized[ $prop ];
		}

		if ( in_array( $type, array( 'checkbox', 'radio', 'dropdown' ), true ) && isset( $val['options'] ) ) {
			$opts = is_string( $val['options'] ) ? $val['options'] : '';
			$opts = array_map( 'trim', explode( ',', $opts ) );
			$opts = array_filter(
				$opts,
				static function ( $s ) {
					return $s !== '';
				}
			);
			$val['options'] = implode( ',', $opts );
		}

		if ( $type === 'paragraph' || $type === 'separator' ) {
			$val['required'] = 0;
		}

		if ( $type === 'file' ) {
			if ( empty( $val['allowed_file_types'] ) ) {
				$val['allowed_file_types'] = sanitize_text_field(
					(string) get_option( 'aol_allowed_file_types', defined( 'ALLOWED_FILE_TYPES' ) ? ALLOWED_FILE_TYPES : '' )
				);
			}
			if ( empty( $val['file_max_size'] ) ) {
				$val['file_max_size'] = (int) get_option( 'aol_upload_max_size', 0 );
			}
		}

		return array(
			'id'   => $normalized['id'],
			'meta' => $val,
		);
	}

	/**
	 * Build schema field from stored post meta.
	 *
	 * @param string $meta_key Meta key (_aol_app_*).
	 * @param array  $val      Meta value.
	 * @return array|null
	 */
	public static function meta_to_schema_field( $meta_key, $val ) {
		if ( ! is_array( $val ) ) {
			return null;
		}

		$id = str_replace( '_aol_app_', '', (string) $meta_key );
		$field = array_merge(
			array(
				'id'    => sanitize_key( $id ),
				'label' => isset( $val['label'] ) ? sanitize_text_field( (string) $val['label'] ) : sanitize_text_field( $id ),
				'type'  => isset( $val['type'] ) ? sanitize_key( (string) $val['type'] ) : 'text',
			),
			$val
		);

		if ( isset( $field['type'] ) && $field['type'] === 'seprator' ) {
			$field['type'] = 'separator';
		}

		return self::normalize_schema_field( $field );
	}

	/**
	 * @return array<string, array>
	 */
	private static function build_registry() {
		return array(
			'text'      => array(
				'type'        => 'text',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Text', 'apply-online' ),
				'icon'       => 'dashicons-editor-textcolor',
				'properties' => array( 'id', 'label', 'required', 'placeholder', 'description', 'class', 'limit' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'input',
				'inputType'  => 'text',
			),
			'text_area' => array(
				'type'        => 'text_area',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Textarea', 'apply-online' ),
				'icon'       => 'dashicons-format-aside',
				'properties' => array( 'id', 'label', 'required', 'placeholder', 'description', 'class', 'limit' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'textarea',
			),
			'number'    => array(
				'type'        => 'number',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Number', 'apply-online' ),
				'icon'       => 'dashicons-editor-ol',
				'properties' => array( 'id', 'label', 'required', 'placeholder', 'description', 'class', 'limit' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'input',
				'inputType'  => 'number',
			),
			'email'     => array(
				'type'        => 'email',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Email', 'apply-online' ),
				'icon'       => 'dashicons-email-alt',
				'properties' => array( 'id', 'label', 'required', 'placeholder', 'description', 'class', 'limit' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'input',
				'inputType'  => 'email',
			),
			'date'      => array(
				'type'        => 'date',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Date', 'apply-online' ),
				'icon'       => 'dashicons-calendar',
				'properties' => array( 'id', 'label', 'required', 'placeholder', 'description', 'class', 'limit' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'input',
				'inputType'  => 'date',
			),
			'checkbox'  => array(
				'type'        => 'checkbox',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Checkbox', 'apply-online' ),
				'icon'       => 'dashicons-yes',
				'properties' => array( 'id', 'label', 'required', 'description', 'class', 'options' ),
				'defaults'   => array( 'required' => 0, 'options' => '' ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'choices',
				'choices'    => 'checkbox',
			),
			'radio'     => array(
				'type'        => 'radio',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Radio', 'apply-online' ),
				'icon'       => 'dashicons-marker',
				'properties' => array( 'id', 'label', 'required', 'description', 'class', 'options', 'preselect' ),
				'defaults'   => array( 'required' => 0, 'options' => '', 'preselect' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'choices',
				'choices'    => 'radio',
			),
			'dropdown'  => array(
				'type'        => 'dropdown',
				'elementKind' => 'input',
				'label'      => esc_html__( 'Dropdown', 'apply-online' ),
				'icon'       => 'dashicons-sort',
				'properties' => array( 'id', 'label', 'required', 'description', 'class', 'options' ),
				'defaults'   => array( 'required' => 0, 'options' => '' ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'select',
			),
			'file'      => array(
				'type'        => 'file',
				'elementKind' => 'input',
				'label'      => esc_html__( 'File', 'apply-online' ),
				'icon'       => 'dashicons-paperclip',
				'properties' => array( 'id', 'label', 'required', 'description', 'class', 'allowed_file_types', 'file_max_size' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'file',
			),
			'separator' => array(
				'type'        => 'separator',
				'elementKind' => 'section',
				'label'      => esc_html__( 'Separator', 'apply-online' ),
				'icon'       => 'dashicons-minus',
				'properties' => array( 'id', 'label', 'description' ),
				'defaults'   => array( 'required' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'separator',
			),
			'paragraph' => array(
				'type'        => 'paragraph',
				'elementKind' => 'section',
				'label'      => esc_html__( 'Paragraph', 'apply-online' ),
				'icon'       => 'dashicons-editor-justify',
				'properties' => array( 'id', 'label', 'text', 'height' ),
				'defaults'   => array( 'required' => 0, 'text' => '', 'height' => 0 ),
				'rules'      => array( 'id' => 'required', 'label' => 'required' ),
				'preview'    => 'paragraph',
			),
		);
	}

	/**
	 * @param string $prop  Property name.
	 * @param mixed  $value Raw value.
	 * @param string $type  Field type.
	 * @return mixed
	 */
	private static function sanitize_property( $prop, $value, $type ) {
		switch ( $prop ) {
			case 'required':
			case 'preselect':
				return ! empty( $value ) ? 1 : 0;
			case 'height':
			case 'limit':
			case 'file_max_size':
				return max( 0, (int) $value );
			case 'text':
				return sanitize_textarea_field( (string) $value );
			case 'options':
				return sanitize_text_field( (string) $value );
			case 'allowed_file_types':
				return sanitize_text_field( (string) $value );
			case 'description':
			case 'placeholder':
			case 'class':
			case 'label':
				return sanitize_text_field( (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * @param string $prop Property name.
	 * @return mixed
	 */
	private static function default_property_value( $prop ) {
		switch ( $prop ) {
			case 'required':
			case 'preselect':
				return 0;
			case 'height':
			case 'limit':
			case 'file_max_size':
				return 0;
			default:
				return '';
		}
	}
}

endif;
