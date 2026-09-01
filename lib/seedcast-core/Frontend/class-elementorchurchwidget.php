<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * Loaded on demand by ElementorChurch::register(), never on a site without
 * Elementor, because it extends a class Elementor owns.
 *
 * @package Seedcast\Core\Frontend
 */

namespace Seedcast\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Church details as an Elementor widget.
 */
class ElementorChurchWidget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'seedcast-church-details';
	}

	/**
	 * Label shown in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Church Details', 'seedcast-sermon-library' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-map-pin';
	}

	/**
	 * Panel category.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords in the Elementor panel.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'church', 'address', 'service times', 'seedcast', 'directions' );
	}

	/**
	 * Panel controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'sc_church_content',
			array(
				'label' => __( 'Church Details', 'seedcast-sermon-library' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'sc_church_intro',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'These details are edited once under Settings, Seedcast, Church. Every place they appear updates together.', 'seedcast-sermon-library' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'seedcast-sermon-library' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Optional', 'seedcast-sermon-library' ),
			)
		);

		$this->add_control(
			'fields',
			array(
				'label'    => __( 'Show', 'seedcast-sermon-library' ),
				'type'     => \Elementor\Controls_Manager::SELECT2,
				'multiple' => true,
				'default'  => array( 'name', 'address', 'times', 'phone', 'email', 'directions' ),
				'options'  => array(
					'name'       => __( 'Church name', 'seedcast-sermon-library' ),
					'address'    => __( 'Address', 'seedcast-sermon-library' ),
					'times'      => __( 'Service times', 'seedcast-sermon-library' ),
					'phone'      => __( 'Phone', 'seedcast-sermon-library' ),
					'email'      => __( 'Email', 'seedcast-sermon-library' ),
					'events'     => __( 'Events link', 'seedcast-sermon-library' ),
					'note'       => __( 'Note to visitors', 'seedcast-sermon-library' ),
					'directions' => __( 'Directions button', 'seedcast-sermon-library' ),
				),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'seedcast-sermon-library' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'stacked',
				'options' => array(
					'stacked' => __( 'Stacked', 'seedcast-sermon-library' ),
					'inline'  => __( 'Inline', 'seedcast-sermon-library' ),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end output.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$fields = isset( $settings['fields'] ) && is_array( $settings['fields'] )
			? array_values( array_intersect( $settings['fields'], ChurchDetails::FIELDS ) )
			: array();

		$args = array(
			'heading' => isset( $settings['heading'] ) ? sanitize_text_field( $settings['heading'] ) : '',
			'layout'  => isset( $settings['layout'] ) ? sanitize_key( $settings['layout'] ) : 'stacked',
		);

		/*
		 * Only pass fields when the editor actually chose some. An empty array
		 * is a set value as far as wp_parse_args is concerned, so passing it
		 * through would render an empty block rather than falling back to the
		 * default field list.
		 */
		if ( ! empty( $fields ) ) {
			$args['fields'] = $fields;
		}

		$html = ChurchDetails::render( $args );

		if ( '' === $html && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$html = '<p class="sc-church__placeholder">'
				. esc_html__( 'Add your church details under Settings, Seedcast, Church.', 'seedcast-sermon-library' )
				. '</p>';
		}

		// ChurchDetails::render() escapes every value it emits.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}
}
