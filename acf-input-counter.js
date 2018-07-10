(function ($) {

	/**
	 * Set maxlength data attribute of wysiwyg textarea field
	 *
	 * We don't use the maxlength attribute as that limits the length
	 * in text mode to content including all HTML tags.
	 *
	 * @param $acf_field
	 */
	acf.set_wysiwyg_textarea_maxlength = function ($acf_field) {

		var fieldkey = $acf_field.data('key');

		/** @var object acf_input_counter_data set in HTML */
		var maxlength = acf_input_counter_data[fieldkey];

		$acf_field.data('maxlength', maxlength);

		return maxlength;
	};

	/**
	 * Change the counter
	 *
	 * @param content
	 * @param $textarea
	 */
	acf.change_counter = function(content, $textarea) {
		var counter = $textarea.closest('.acf-input').find('.count');
		if (counter.length === 0) {
			return;
		}
		var length = content.length;
		counter.text(length);
	};

	/**
	 * Get length of content after removing HTML tags so we are only counting
	 * visible characters.
	 */
	acf.get_content_without_tags = function( content_html ) {
		return acf.decode( content_html.replace(/<\/?[^>]+(>|$)/g, "") );
	};

	/**
	 * Have we reached the max length of the wysiwyg textarea field
	 *
	 * @param e
	 * @param content
	 * @param maxlength
	 *
	 * @returns {boolean}
	 */
	acf.wysiwyg_max_length_reached = function(e, content, maxlength ) {
		var allowed_keystroke = false;

		//maxlength not defined see acf.set_wysiwyg_textarea_maxlength() below
		if (typeof (maxlength) === 'undefined' || ! maxlength ) return;

		var length = acf.get_content_without_tags( content ).length;

		var valid_keycodes = [
			8, //backspace
			33, //home
			34, //end
			35, //pagedown
			36, //pageup
			37, //left
			38, //up
			39, //right
			40, //down
			46 //delete
		];

		//windows - ctrlKey = control key
		//mac - metaKey = cmd key
		var cmd = ( e.ctrlKey || e.metaKey );

		//never prevent delete keys being used
		if ( $.inArray( e.keyCode, valid_keycodes ) > -1
			|| ( cmd && e.keyCode === 88) // cut
			|| ( cmd && e.keyCode === 67) // ctrl+c
		) {
			allowed_keystroke = true;
		}

		return ( ! allowed_keystroke && length >= maxlength );
	};

	/**
	 * Add tinyMCE events for counting & limiting content length
	 * of wysiwyg field in visual mode
	 */
	acf.add_filter('wysiwyg_tinymce_settings', function (init, id) {

		var $acf_field = $('#'+id).parents('.acf-field');
		var maxlength = acf.set_wysiwyg_textarea_maxlength( $acf_field );

		//extend parent init.setup() method
		var _setup = init.setup;
		init.setup = function(editor) {

			/**
			 * Limit length to maxlength
			 */
			editor.on('keyDown', function(e) {
				var content = editor.getContent();

				//don't allow typing after maxlength
				if( acf.wysiwyg_max_length_reached(e, content, maxlength ) ) {
					tinymce.dom.Event.cancel(e);
				}
			});

			/*
			 * Update character counter
			 */
			editor.on('keyUp', function () {
				var content = acf.get_content_without_tags( editor.getContent() );
				var $textarea = $(editor.getElement());
				acf.change_counter( content, $textarea );
			});

			//run parent method
			return _setup.apply(this, arguments);
		};

		return init;
	});

	/**
	 * Setup events for text field counter
	 */
	acf.fields.text_counter = acf.field.extend({
		type: 'text',
		events: {
			'input input': 'change_count',
			'focus input': 'change_count'
		},
		change_count: function (e) {
			var content = e.$el.val();
			var $textarea = e.$el;
			acf.change_counter( content, $textarea );
		}
	});

	/**
	 * Setup events for textarea field counter
	 */
	acf.fields.textarea_counter = acf.field.extend({
		type: 'textarea',
		events: {
			'input textarea': 'change_count',
			'focus textarea': 'change_count'
		},
		change_count: function(e) {
			var content = e.$el.val();
			var $textarea = e.$el;
			acf.change_counter( content, $textarea );
		}

	});

	/**
	 * Setup events for wysiwyg counter
	 */
	acf.fields.wysiwyg_counter = acf.field.extend({
		type: 'wysiwyg',
		actions: {
			'load': 'set_wysiwyg_textarea_maxlength'
		},
		set_wysiwyg_textarea_maxlength: function () {
			acf.set_wysiwyg_textarea_maxlength(this.$field);
		},

		//events are for wysiwyg field in text mode
		events: {
			'input .wp-editor-area': 'change_count',
			'focus .wp-editor-area': 'change_count',
			'keydown .wp-editor-area': 'limit_chars',
			'click .wp-media-buttons button': 'check_editor'
		},

		//change the counter
		change_count: function (e) {
			var content = acf.get_content_without_tags( e.$el.val() );
			var $textarea = e.$el;
			acf.change_counter( content, $textarea );
		},

		//limit chars to maxlength
		limit_chars: function (e) {
			var content = acf.get_content_without_tags( e.$el.val() );
			var $textarea = e.$el;
			var maxlength = $textarea.parents('.acf-field').data('maxlength');

			//don't allow typing after maxlength
			if( acf.wysiwyg_max_length_reached(e, content, maxlength ) ) {
				e.preventDefault();
			}
		},

		//force triggering "change" event on tinymce/quicktags switching
		check_editor: function () {
			$(tinyMCE.activeEditor.targetElm).trigger('change').focus();
		}

	});

})(jQuery);
