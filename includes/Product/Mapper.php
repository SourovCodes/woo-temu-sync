<?php
/**
 * WooCommerce product to Temu V3 payload mapping.
 *
 * @package WooTemuSync
 */

namespace WooTemuSync\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a temu.local.goods.v3.add request from a WooCommerce product.
 *
 * V3 is deliberately forgiving: rather than making the caller walk Temu's
 * category tree, upload images and match attribute ids, it accepts the store's
 * own raw data and normalises it server side. Categories come from a free text
 * hint, images from public URLs, attributes from whatever names the store uses.
 * So this mapper's job is to hand over as much honest product data as possible,
 * not to second-guess Temu's taxonomy.
 */
class Mapper {

	/**
	 * Build the full request payload for a product.
	 *
	 * @param \WC_Product $product Product to publish.
	 * @return array
	 *
	 * @throws MappingException When the product cannot be represented.
	 */
	public function to_payload( $product ) {
		$payload = array(
			'goodsBasic' => $this->goods_basic( $product ),
			'skuList'    => $this->sku_list( $product ),
		);

		$attributes = $this->attributes( $product );
		if ( ! empty( $attributes ) ) {
			$payload['attributes'] = $attributes;
		}

		if ( empty( $payload['skuList'] ) ) {
			throw new MappingException(
				sprintf(
					/* translators: %s: product name. */
					esc_html__( '"%s" has no sellable variations to publish.', 'woo-temu-sync' ),
					esc_html( $product->get_name() )
				)
			);
		}

		/**
		 * Filter the finished Temu product payload.
		 *
		 * @since 0.1.0
		 *
		 * @param array       $payload Request payload.
		 * @param \WC_Product $product Source product.
		 */
		return apply_filters( 'wtsync_product_payload', $payload, $product );
	}

	/**
	 * Build the goodsBasic block.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 *
	 * @throws MappingException When required data is missing.
	 */
	protected function goods_basic( $product ) {
		$name = Schema::plain_text( $product->get_name(), Schema::MAX_GOODS_NAME );

		if ( '' === $name ) {
			throw new MappingException( esc_html__( 'The product has no name.', 'woo-temu-sync' ) );
		}

		$basic = array(
			'externalGoodsId' => $this->external_goods_id( $product ),
			'goodsName'       => $name,
			'productType'     => Schema::TYPE_NORMAL,
		);

		$description = $this->description( $product );
		if ( '' !== $description ) {
			$basic['goodsDesc'] = $description;
		}

		$category = $this->category_hint( $product );
		if ( '' !== $category ) {
			$basic['extCatName'] = $category;
		}

		$carousel = $this->carousel_images( $product );
		if ( ! empty( $carousel ) ) {
			$basic['goodsCarouselImage'] = $carousel;
		}

		return $basic;
	}

	/**
	 * Stable external identifier for a product.
	 *
	 * Temu keys off this to tell an update from a new listing, so it must never
	 * change for the life of the product. It is prefixed rather than being the
	 * bare post id so that two stores publishing into the same Temu mall do not
	 * collide.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function external_goods_id( $product ) {
		$id = 'wc-' . $product->get_id();

		/**
		 * Filter the external product identifier.
		 *
		 * @since 0.1.0
		 *
		 * @param string      $id      Identifier.
		 * @param \WC_Product $product Product.
		 */
		$id = (string) apply_filters( 'wtsync_external_goods_id', $id, $product );

		return Schema::truncate( $id, Schema::MAX_EXTERNAL_ID );
	}

	/**
	 * Stable external identifier for a single SKU.
	 *
	 * @param \WC_Product $variation Simple product or variation.
	 * @return string
	 */
	public function external_sku_id( $variation ) {
		$id = 'wc-sku-' . $variation->get_id();

		/**
		 * Filter the external SKU identifier.
		 *
		 * @since 0.1.0
		 *
		 * @param string      $id        Identifier.
		 * @param \WC_Product $variation Product or variation.
		 */
		$id = (string) apply_filters( 'wtsync_external_sku_id', $id, $variation );

		return Schema::truncate( $id, Schema::MAX_EXTERNAL_ID );
	}

	/**
	 * Product description.
	 *
	 * The long description is preferred over the short one because V3 mines it
	 * for compliance and manufacturer details, so more prose is genuinely
	 * better here.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected function description( $product ) {
		$description = $product->get_description();

		if ( '' === trim( (string) $description ) ) {
			$description = $product->get_short_description();
		}

		return Schema::rich_text( $description, Schema::MAX_GOODS_DESC );
	}

	/**
	 * Free text category hint.
	 *
	 * Temu recommends a category from this string, so the deepest category path
	 * in the store is sent rather than a flat list of every assigned term.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	protected function category_hint( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$paths = array();

		foreach ( $terms as $term ) {
			$path        = array( $term->name );
			$ancestor_id = (int) $term->parent;
			$guard       = 0;

			// Walk up to the root, with a guard against a corrupted term tree.
			while ( $ancestor_id > 0 && $guard < 10 ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );

				if ( ! $ancestor || is_wp_error( $ancestor ) ) {
					break;
				}

				array_unshift( $path, $ancestor->name );
				$ancestor_id = (int) $ancestor->parent;
				++$guard;
			}

			$paths[] = implode( ' / ', $path );
		}

		// The longest path is the most specific, which gives Temu the best hint.
		usort(
			$paths,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return Schema::plain_text( $paths[0], Schema::MAX_EXT_CAT_NAME );
	}

	/**
	 * Carousel images: the featured image first, then the gallery.
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	protected function carousel_images( $product ) {
		$ids = array_merge(
			array( $product->get_image_id() ),
			$product->get_gallery_image_ids()
		);

		return $this->image_urls( $ids, Schema::MAX_CAROUSEL_IMAGE );
	}

	/**
	 * Resolve attachment ids to public URLs, dropping anything unusable.
	 *
	 * @param array $ids   Attachment ids.
	 * @param int   $limit Maximum images.
	 * @return string[]
	 */
	protected function image_urls( array $ids, $limit ) {
		$urls = array();

		foreach ( $ids as $id ) {
			if ( ! $id ) {
				continue;
			}

			$url = wp_get_attachment_url( $id );

			if ( ! $url || ! Schema::is_public_image_url( $url ) ) {
				continue;
			}

			$urls[] = $url;

			if ( count( $urls ) >= $limit ) {
				break;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Product level attributes.
	 *
	 * Everything the store knows is sent, including the brand and any custom
	 * attributes, because V3 uses these to infer category, specifications and
	 * compliance data. Attributes used to build variations are excluded: those
	 * belong on the SKU as variations, not on the product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	protected function attributes( $product ) {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_variation' ) && $attribute->get_variation() ) {
				continue;
			}

			$name = Schema::plain_text( wc_attribute_label( $attribute->get_name(), $product ), Schema::MAX_ATTR_LENGTH );

			if ( '' === $name ) {
				continue;
			}

			$values = array();

			foreach ( $this->attribute_values( $attribute ) as $value ) {
				$value = Schema::plain_text( $value, Schema::MAX_ATTR_LENGTH );

				if ( '' !== $value ) {
					$values[] = $value;
				}

				if ( count( $values ) >= Schema::MAX_ATTR_VALUES ) {
					break;
				}
			}

			if ( empty( $values ) ) {
				continue;
			}

			$attributes[] = array(
				'name'  => $name,
				'value' => array_values( array_unique( $values ) ),
			);

			if ( count( $attributes ) >= Schema::MAX_ATTRIBUTES ) {
				break;
			}
		}

		/**
		 * Filter the product attributes sent to Temu.
		 *
		 * The natural place to inject GPSR data such as the manufacturer or the
		 * EU Responsible Person, which V3 extracts from attributes and prose.
		 *
		 * @since 0.1.0
		 *
		 * @param array       $attributes Attribute list.
		 * @param \WC_Product $product    Product.
		 */
		return apply_filters( 'wtsync_product_attributes', $attributes, $product );
	}

	/**
	 * Read the values of one attribute.
	 *
	 * @param object $attribute Attribute object.
	 * @return array
	 */
	protected function attribute_values( $attribute ) {
		if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
			$terms  = $attribute->get_terms();
			$values = array();

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$values[] = $term->name;
				}
			}

			return $values;
		}

		return (array) $attribute->get_options();
	}

	/**
	 * Build the SKU list.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	protected function sku_list( $product ) {
		$skus = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );

				if ( ! $variation || ! $variation->exists() ) {
					continue;
				}

				$sku = $this->sku( $variation, $product );

				if ( null !== $sku ) {
					$skus[] = $sku;
				}

				if ( count( $skus ) >= Schema::MAX_SKUS ) {
					break;
				}
			}

			return $skus;
		}

		$sku = $this->sku( $product, $product );

		return null === $sku ? array() : array( $sku );
	}

	/**
	 * Build a single SKU entry.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @param \WC_Product $parent_product    Parent product.
	 * @return array|null Null when the variation cannot be sold.
	 */
	protected function sku( $variation, $parent_product ) {
		$price = $this->price( $variation );

		// Without a price there is nothing to sell; Temu rejects the SKU anyway.
		if ( null === $price ) {
			return null;
		}

		$images = $this->image_urls(
			array( $variation->get_image_id() ),
			Schema::MAX_SKU_IMAGE
		);

		// SKU images are required, so fall back to the parent's gallery rather
		// than losing the variation entirely.
		if ( empty( $images ) ) {
			$images = $this->carousel_images( $parent_product );
		}

		if ( empty( $images ) ) {
			return null;
		}

		$sku = array(
			'externalSkuId' => $this->external_sku_id( $variation ),
			'images'        => array_slice( $images, 0, Schema::MAX_SKU_IMAGE ),
			'price'         => $price,
			'quantity'      => $this->quantity( $variation ),
		);

		$package = $this->package_info( $variation, $parent_product );
		if ( ! empty( $package ) ) {
			$sku['packageInfo'] = $package;
		}

		$variations = $this->variations( $variation, $parent_product );
		if ( ! empty( $variations ) ) {
			$sku['variations'] = $variations;
		}

		$barcode = $this->barcode( $variation );
		if ( ! empty( $barcode ) ) {
			$sku['barCode'] = $barcode;
		}

		return $sku;
	}

	/**
	 * Build the price block.
	 *
	 * The basePrice is what the buyer pays; listPrice is the struck-through
	 * reference, so it is only sent when the store is actually running a sale.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @return array|null
	 */
	protected function price( $variation ) {
		$active  = $variation->get_price();
		$regular = $variation->get_regular_price();

		if ( '' === $active || null === $active ) {
			return null;
		}

		$currency = get_woocommerce_currency();
		$decimals = wc_get_price_decimals();

		$price = array(
			'basePrice' => array(
				'amount'   => Schema::money( $active, $decimals ),
				'currency' => $currency,
			),
		);

		if ( '' !== $regular && (float) $regular > (float) $active ) {
			$price['listPrice'] = array(
				'amount'   => Schema::money( $regular, $decimals ),
				'currency' => $currency,
			);
		}

		return $price;
	}

	/**
	 * Sellable quantity.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @return int
	 */
	protected function quantity( $variation ) {
		if ( $variation->managing_stock() ) {
			return max( 0, (int) $variation->get_stock_quantity() );
		}

		/**
		 * Filter the quantity reported for products that do not manage stock.
		 *
		 * Temu needs a number, and treats a missing one as zero, which would
		 * list the product as sold out.
		 *
		 * @since 0.1.0
		 *
		 * @param int         $quantity  Default quantity.
		 * @param \WC_Product $variation Variation or simple product.
		 */
		$default = (int) apply_filters( 'wtsync_unmanaged_stock_quantity', 100, $variation );

		return $variation->is_in_stock() ? max( 0, $default ) : 0;
	}

	/**
	 * Package weight and dimensions, converted to Temu's units.
	 *
	 * Temu expects grams and centimetres with no unit suffix. WooCommerce
	 * stores whatever the shop configured, so conversion is not optional: a
	 * store working in pounds would otherwise ship weights out by a factor of
	 * 450.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @param \WC_Product $parent_product    Parent product.
	 * @return array
	 */
	protected function package_info( $variation, $parent_product ) {
		$source = $variation->has_weight() || $variation->has_dimensions() ? $variation : $parent_product;

		$weight = $source->get_weight();
		$length = $source->get_length();
		$width  = $source->get_width();
		$height = $source->get_height();

		$package = array();

		if ( '' !== $weight ) {
			$grams = wc_get_weight( (float) $weight, 'g' );
			$value = Schema::measurement( $grams, 0 );

			if ( '' !== $value ) {
				$package['weight'] = $value;
			}
		}

		$dimensions = array(
			'length' => $length,
			'width'  => $width,
			'height' => $height,
		);

		foreach ( $dimensions as $key => $raw ) {
			if ( '' === $raw ) {
				continue;
			}

			$value = Schema::measurement( wc_get_dimension( (float) $raw, 'cm' ), 2 );

			if ( '' !== $value ) {
				$package[ $key ] = $value;
			}
		}

		// A partial package block invites Temu to substitute defaults for the
		// missing halves, which silently misprices shipping. All or nothing.
		$complete = array( 'weight', 'length', 'width', 'height' );

		foreach ( $complete as $key ) {
			if ( ! isset( $package[ $key ] ) ) {
				return array();
			}
		}

		return $package;
	}

	/**
	 * Variation axes for a SKU.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @param \WC_Product $parent_product    Parent product.
	 * @return array
	 */
	protected function variations( $variation, $parent_product ) {
		if ( ! method_exists( $variation, 'get_variation_attributes' ) ) {
			return array();
		}

		$variations = array();

		foreach ( $variation->get_variation_attributes() as $key => $value ) {
			$taxonomy = str_replace( 'attribute_', '', $key );
			$name     = Schema::plain_text( wc_attribute_label( $taxonomy, $parent_product ), Schema::MAX_ATTR_LENGTH );
			$label    = $this->variation_value_label( $taxonomy, $value );

			if ( '' === $name || '' === $label ) {
				continue;
			}

			$variations[] = array(
				'name'  => $name,
				'value' => $label,
			);

			if ( count( $variations ) >= Schema::MAX_VARIATIONS ) {
				break;
			}
		}

		return $variations;
	}

	/**
	 * Human readable label for a variation value.
	 *
	 * @param string $taxonomy Attribute taxonomy or name.
	 * @param string $value    Stored value, a term slug for taxonomy attributes.
	 * @return string
	 */
	protected function variation_value_label( $taxonomy, $value ) {
		if ( '' === $value ) {
			return '';
		}

		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );

			if ( $term && ! is_wp_error( $term ) ) {
				$value = $term->name;
			}
		}

		return Schema::plain_text( $value, Schema::MAX_ATTR_LENGTH );
	}

	/**
	 * Barcode block, from WooCommerce's native GTIN field.
	 *
	 * @param \WC_Product $variation Variation or simple product.
	 * @return array
	 */
	protected function barcode( $variation ) {
		if ( ! method_exists( $variation, 'get_global_unique_id' ) ) {
			return array();
		}

		$code = trim( (string) $variation->get_global_unique_id() );

		if ( '' === $code ) {
			return array();
		}

		$type = Schema::detect_barcode_type( $code );

		if ( '' === $type ) {
			return array();
		}

		return array(
			'barCodeType' => $type,
			'barCodeId'   => array( $code ),
		);
	}
}
