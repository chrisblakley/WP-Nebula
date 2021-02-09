//Check if the user has enabled DNT (if supported in their browser)
nebula.isDoNotTrack = function(){
	//Use server-side header detection first
	if ( nebula?.user?.dnt == 1 ){
		return true; //This user prefers not to be tracked
	} else {
		return false; //This user is allowing tracking.
	}

	//Otherwise, check if the browser supports DNT
	if ( window.doNotTrack || navigator.doNotTrack || navigator.msDoNotTrack || 'msTrackingProtectionEnabled' in window.external ){
		//Check if DNT is enabled
		if ( window.doNotTrack == '1' || navigator.doNotTrack == 'yes' || navigator.doNotTrack == '1' || navigator.msDoNotTrack == '1' || window.external.msTrackingProtectionEnabled() ){
			return true; //This user prefers not to be tracked
		} else {
			return false; //This user is allowing tracking.
		}
	}

	return false; //The browser does not support DNT
};

//Google Analytics Universal Analytics Event Trackers
nebula.eventTracking = async function(){
	if ( nebula.isDoNotTrack() ){
		return false;
	}

	nebula.cacheSelectors(); //If event tracking is initialized by the async GA callback, selectors won't be cached yet

	nebula.once(function(){
		window.dataLayer = window.dataLayer || []; //Prevent overwriting an existing GTM Data Layer array

		nebula.dom.document.trigger('nebula_event_tracking');

		if ( typeof window.ga === 'function' ){
			window.ga(function(tracker){
				nebula.dom.document.trigger('nebula_ga_tracker', tracker);
				nebula.user.cid = tracker.get('clientId');
				window.dataLayer.push(Object.assign({'event': 'nebula-ga-tracker', 'client-id': nebula.user.cid}));
			});
		}

		//Btn Clicks
		nebula.dom.document.on('mousedown', "button, .button, .btn, [role='button'], a.wp-block-button__link, .hs-button", function(e){
			let thisEvent = {
				event: e,
				category: 'Button',
				action: 'Click', //GA4 Name: "button_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				text: jQuery(this).val() || jQuery(this).text() || '(Unknown)',
				link: jQuery(this).attr('href')
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', 'Button Click', thisEvent.text.trim(), thisEvent.link);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-button-click'}));
		});

		//Bootstrap "Collapse" Accordions
		nebula.dom.document.on('shown.bs.collapse', function(e){
			let thisEvent = {
				event: e,
				category: 'Accordion',
				action: 'Shown', //GA4 Name: "accordion_toggle"?
				label: jQuery('[data-target="#' + e.target.id + '"]').text().trim() || e.target.id,
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-accordion-shown'}));
		});
		nebula.dom.document.on('hidden.bs.collapse', function(e){
			let thisEvent = {
				event: e,
				category: 'Accordion',
				action: 'Hidden', //GA4 Name: "accordion_toggle"?
				label: jQuery('[data-target="#' + e.target.id + '"]').text().trim() || e.target.id,
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-accordion-hidden'}));
		});

		//Bootstrap Modals
		nebula.dom.document.on('shown.bs.modal', function(e){
			let thisEvent = {
				event: e,
				category: 'Modal',
				action: 'Shown', //GA4 Name: "modal_toggle"?
				label: jQuery('#' + e.target.id + ' .modal-title').text().trim() || e.target.id,
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-modal-shown'}));
		});
		nebula.dom.document.on('hidden.bs.modal', function(e){
			let thisEvent = {
				event: e,
				category: 'Modal',
				action: 'Hidden', //GA4 Name: "modal_toggle"?
				label: jQuery('#' + e.target.id + ' .modal-title').text().trim() || e.target.id,
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-modal-hidden'}));
		});

		//Bootstrap Carousels (Sliders)
		nebula.dom.document.on('slide.bs.carousel', function(e){
			if ( window.event ){ //Only if sliding manually
				let thisEvent = {
					event: e,
					category: 'Carousel',
					action: e.target.id || e.target.title || e.target.className.replaceAll(/\s/g, '.'), //GA4 Name: "carousel_slide"?
					from: e.from,
					to: e.to,
				};

				thisEvent.activeSlide = jQuery(e.target).find('.carousel-item').eq(e.to);
				thisEvent.activeSlideName = thisEvent.activeSlide.attr('id') || thisEvent.activeSlide.attr('title') || 'Unnamed';
				thisEvent.prevSlide = jQuery(e.target).find('.carousel-item').eq(e.from);
				thisEvent.prevSlideName = thisEvent.prevSlide.attr('id') || thisEvent.prevSlide.attr('title') || 'Unnamed';
				thisEvent.label = 'Slide to ' + thisEvent.to + ' (' + thisEvent.activeSlideName + ') from ' + thisEvent.from + ' (' + thisEvent.prevSlideName + ')';

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-carousel-slide'}));
			}
		});

		//Generic Form Submissions
		//This event will be a duplicate if proper event tracking is setup on each form, but serves as a safety net.
		//It is not recommended to use this event for goal tracking unless absolutely necessary (this event does not check for submission success)!
		nebula.dom.document.on('submit', 'form', function(e){
			let thisEvent = {
				event: e,
				category: 'Generic Form',
				action: 'Submit', //GA4 Name: "form_submit"? How to differentiate it from conversions?
				formID: e.target.id || 'form.' + e.target.className.replaceAll(/\s/g, '.'),
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.formID);
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-generic-form'}));
		});

		//Notable File Downloads
		jQuery.each(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'zipx', 'rar', 'gz', 'tar', 'txt', 'rtf', 'ics', 'vcard'], function(index, extension){
			jQuery("a[href$='." + extension + "' i]").on('mousedown', function(e){ //Cannot defer case insensitive attribute selectors in jQuery (or else you will get an "unrecognized expression" error)
				let thisEvent = {
					event: e,
					category: 'Download',
					action: extension, //GA4 Name: "file_download" Note: This is a default GA4 event and is not needed to be tracked in Nebula. Consider deleting entirely.
					intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
					extension: extension,
					fileName: jQuery(this).attr('href').substr(jQuery(this).attr('href').lastIndexOf('/')+1),
				};

				ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.fileName);
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-download'}));
				if ( typeof fbq === 'function' ){fbq('track', 'ViewContent', {content_name: thisEvent.fileName});}
				if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.fileName);}
				nebula.crm('event', 'File Download');
			});
		});

		//Notable Downloads
		nebula.dom.document.on('mousedown', ".notable a, a.notable", function(e){
			let thisEvent = {
				event: e,
				category: 'Download',
				action: 'Notable', //GA4 Name: "file_download"
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				filePath: jQuery(this).attr('href').trim(),
				linkText: jQuery(this).text()
			};

			if ( thisEvent.filePath.length && thisEvent.filePath !== '#' ){
				thisEvent.fileName = filePath.substr(filePath.lastIndexOf('/')+1);
				ga('set', nebula.analytics.metrics.notableDownloads, 1);
				nebula.dom.document.trigger('nebula_event', thisEvent);

				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.fileName);
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-download'}));
				if ( typeof fbq === 'function' ){fbq('track', 'ViewContent', {content_name: thisEvent.fileName});}
				if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.fileName);}
				nebula.crm('event', 'Notable File Download');
			}
		});

		//Generic Internal Search Tracking
		//This event will need to correspond to the GA4 event name "search" and use "search_term" as a parameter: https://support.google.com/analytics/answer/9267735
		nebula.dom.document.on('submit', '#s, input.search', function(){
			let thisEvent = {
				event: e,
				category: 'Internal Search',
				action: 'Submit', //GA4 Name: "search"
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				query: jQuery(this).find('input[name="s"]').val().toLowerCase().trim()
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.query);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-internal-search'}));
			if ( typeof fbq === 'function' ){fbq('track', 'Search', {search_string: thisEvent.query});}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.query);}
			nebula.crm('identify', {internal_search: thisEvent.query});
		});

		//Keyboard Shortcut (Non-interaction because they are not taking explicit action with the webpage)
		nebula.dom.document.on('keydown', function(e){
			window.modifiedZoomLevel = window.modifiedZoomLevel || 0; //Scope to window so it is not reset every event. Note: This is just how it was modified and not the actual zoom level! Zoom level is saved between pageloads so it may have started at non-zero!

			//Ctrl+ (Zoom In)
			if ( (e.ctrlKey || e.metaKey) && (e.which === 187 || e.which === 107) ){ //187 is plus (and equal), 107 is plus on the numpad
				modifiedZoomLevel++; //Increment the zoom level iterator

				let thisEvent = {
					event: e,
					category: 'Keyboard Shortcut',
					action: 'Zoom In (Ctrl+)', //GA4 Name: "zoom_change"?
					modifiedZoomLevel: modifiedZoomLevel
				};

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, 'Modified Zoom Level: ' + thisEvent.modifiedZoomLevel, {'nonInteraction': true});
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-keyboard-shortcut'}));
			}

			//Ctrl- (Zoom Out)
			if ( (e.ctrlKey || e.metaKey) && (e.which === 189 || e.which === 109) ){ //189 is minus, 109 is minus on the numpad
				modifiedZoomLevel--; //Decrement the zoom level iterator

				let thisEvent = {
					event: e,
					category: 'Keyboard Shortcut',
					action: 'Zoom Out (Ctrl-)', //GA4 Name: "zoom_change"?
					modifiedZoomLevel: modifiedZoomLevel
				};

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, 'Modified Zoom Level: ' + thisEvent.modifiedZoomLevel, {'nonInteraction': true});
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-keyboard-shortcut'}));
			}

			//Ctrl+0 (Reset Zoom)
			if ( (e.ctrlKey || e.metaKey) && (e.which === 48 || e.which === 0 || e.which === 96) ){ //48 is 0 (Mac), 0 is Windows 0, and 96 is Windows numpad
				modifiedZoomLevel = 0; //Reset the zoom level iterator

				let thisEvent = {
					event: e,
					category: 'Keyboard Shortcut',
					action: 'Reset Zoom (Ctrl+0)', //GA4 Name: "zoom_change"?
					modifiedZoomLevel: modifiedZoomLevel
				};

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, 'Modified Zoom Level: ' + thisEvent.modifiedZoomLevel, {'nonInteraction': true});
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-keyboard-shortcut'}));
			}

			//Ctrl+F or Cmd+F (Find)
			if ( (e.ctrlKey || e.metaKey) && e.which === 70 ){
				let thisEvent = {
					event: e,
					category: 'Keyboard Shortcut',
					action: 'Find on Page (Ctrl+F)', //GA4 Name: "search" but we will not have a "search_term" parameter. Make sure we do have something to note that this is a Find On Page
					highlightedText: window.getSelection().toString().trim() || '(No highlighted text when initiating find)'
				};

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.highlightedText, {'nonInteraction': true});
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-keyboard-shortcut'}));
			}

			//Ctrl+D or Cmd+D (Bookmark)
			if ( (e.ctrlKey || e.metaKey) && e.which === 68 ){ //Ctrl+D
				let thisEvent = {
					event: e,
					category: 'Keyboard Shortcut',
					action: 'Bookmark (Ctrl+D)', //GA4 Name: "bookmark"?
					label: 'User bookmarked the page (with keyboard shortcut)'
				};

				nebula.removeQueryParameter(['utm_campaign', 'utm_medium', 'utm_source', 'utm_content', 'utm_term'], window.location.href); //Remove existing UTM parameters
				history.replaceState(null, document.title, window.location.href + '?utm_source=bookmark');
				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label, {'nonInteraction': true});
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-keyboard-shortcut'}));
			}
		});

		//Mailto link tracking
		nebula.dom.document.on('mousedown', 'a[href^="mailto"]', function(e){
			let thisEvent = {
				event: e,
				category: 'Contact',
				action: 'Mailto', //GA4 Name: "mailto"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				emailAddress: jQuery(this).attr('href').replace('mailto:', '')
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			ga('set', nebula.analytics.dimensions.contactMethod, thisEvent.action);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.emailAddress);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-mailto'}));
			if ( typeof fbq === 'function' ){fbq('track', 'Lead', {content_name: thisEvent.action});}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', thisEvent.action);
			nebula.crm('identify', {mailto_contacted: thisEvent.emailAddress});
		});

		//Telephone link tracking
		nebula.dom.document.on('mousedown', 'a[href^="tel"]', function(e){
			let thisEvent = {
				event: e,
				category: 'Contact',
				action: 'Click-to-Call', //GA4 Name: "click_to_call"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				phoneNumber: jQuery(this).attr('href').replace('tel:', '')
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			ga('set', nebula.analytics.dimensions.contactMethod, thisEvent.action);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.phoneNumber);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-click-to-call'}));
			if ( typeof fbq === 'function' ){fbq('track', 'Lead', {content_name: thisEvent.action});}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', thisEvent.action);
			nebula.crm('identify', {phone_contacted: thisEvent.phoneNumber});
		});

		//SMS link tracking
		nebula.dom.document.on('mousedown', 'a[href^="sms"]', function(e){
			let thisEvent = {
				event: e,
				category: 'Contact',
				action: 'SMS', //GA4 Name: "sms"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				phoneNumber: jQuery(this).attr('href').replace('tel:', '')
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			ga('set', nebula.analytics.dimensions.contactMethod, thisEvent.action);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.phoneNumber);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-sms'}));
			if ( typeof fbq === 'function' ){fbq('track', 'Lead', {content_name: thisEvent.action});}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', thisEvent.action);
			nebula.crm('identify', {phone_contacted: thisEvent.phoneNumber});
		});

		//Street Address click //@todo "Nebula" 0: How to detect when a user clicks an address that is not linked, but mobile opens the map anyway? What about when it *is* linked?

		//Utility Navigation Menu
		nebula.dom.document.on('mousedown', '#utility-nav ul.menu a', function(e){
			let thisEvent = {
				event: e,
				category: 'Navigation Menu',
				action: 'Utility Menu', //GA4 Name: "menu_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-navigation-menu-click'}));
		});

		//Primary Navigation Menu
		nebula.dom.document.on('mousedown', '#primary-nav ul.menu a', function(e){
			let thisEvent = {
				event: e,
				category: 'Navigation Menu',
				action: 'Primary Menu', //GA4 Name: "menu_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-navigation-menu-click'}));
		});

		//Offcanvas Navigation Menu (Mmenu)
		nebula.dom.document.on('mousedown', '#offcanvasnav .mm-menu li a:not(.mm-next)', function(e){
			let thisEvent = {
				event: e,
				category: 'Navigation Menu',
				action: 'Offcanvas Menu', //GA4 Name: "menu_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-navigation-menu-click'}));
		});

		//Breadcrumb Navigation
		nebula.dom.document.on('mousedown', 'ol.nebula-breadcrumbs a', function(e){
			let thisEvent = {
				event: e,
				category: 'Navigation Menu',
				action: 'Breadcrumbs', //GA4 Name: "menu_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-navigation-menu-click'}));
		});

		//Sidebar Navigation Menu
		nebula.dom.document.on('mousedown', '#sidebar-section ul.menu a', function(e){
			let thisEvent = {
				event: e,
				category: 'Navigation Menu',
				action: 'Sidebar Menu', //GA4 Name: "menu_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-navigation-menu-click'}));
		});

		//Footer Navigation Menu
		nebula.dom.document.on('mousedown', '#powerfooter ul.menu a', function(e){
			let thisEvent = {
				event: e,
				category: 'Navigation Menu',
				action: 'Footer Menu', //GA4 Name: "menu_click"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-navigation-menu-click'}));
		});

		//Nebula Cookie Notification link clicks
		nebula.dom.document.on('mousedown', '#nebula-cookie-notification a', function(e){
			let thisEvent = {
				event: e,
				category: 'Cookie Notification',
				action: 'Click', //GA4 Name: "cookie_notification"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				text: jQuery(this).text(),
				link: jQuery(this).attr('href')
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.text.trim(), thisEvent.link, {'nonInteraction': true}); //Non-interaction because the user is not interacting with any content yet so this should not influence the bounce rate
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-cookie-notification-click'}));
		});

		//History Popstate (dynamic URL changes via the History API when "states" are pushed into the browser history)
		if ( typeof history.pushState === 'function' ){
			nebula.dom.window.on('popstate', function(e){ //When a state that was previously pushed is used, or "popped". This *only* triggers when a pushed state is popped!
				let thisEvent = {
					event: e,
					category: 'History Popstate',
					action: document.title,
					location: document.location,
					state: JSON.stringify(e.state)
				};

				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.location);
			});
		}

		//Dead Clicks (Non-Linked Click Attempts)
		nebula.dom.document.on('click', 'img', function(e){
			if ( !jQuery(this).parents('a, button').length ){
				let thisEvent = {
					event: e,
					category: 'Dead Click',
					action: 'Image', //GA4 Name: "dead_click"?
					element: 'Image',
					src: jQuery(this).attr('src')
				};

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.src, {'nonInteraction': true}); //Non-interaction because if the user leaves due to this it should be considered a bounce
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-dead-click'}));
				nebula.crm('event', thisEvent.category);
			}
		});

		//Detect "Rage Clicks"
		let clickEvents = [];
		nebula.dom.document.on('click', 'body', function(e){
			//Ignore clicks on certain elements that typically incur many clicks
			if ( jQuery(this).is('input[type="number"]') ){
				return null;
			}

			clickEvents.push({
				event: e,
				time: new Date()
			});

			//Keep only required number of click events
			if ( clickEvents.length > 5 ){ //If there are more than 5 click events
				clickEvents.splice(0, clickEvents.length - 5); //Remove everything except the latest 5
			}

			//Detect 3 clicks in 5 seconds
			if ( clickEvents.length >= 5 ){
				const numberOfClicks = 5; //Number of clicks to detect within the period
				const period = 3; //The period to listen for the number of clicks

				let last = clickEvents.length - 1; //The latest click event
				let timeDiff = (clickEvents[last].time.getTime() - clickEvents[last - numberOfClicks + 1].time.getTime()) / 1000; //Time between the last click and previous click

				//Ignore event periods longer than desired
				if ( timeDiff > period ){
					return null; //Return null because false will prevent regular clicks!
				}

				//Loop through the last number of click events to check the distance between them
				let max_distance = 0;
				for ( let i = last - numberOfClicks+1; i < last; i++ ){ //Consider for... of loop here?
					for ( let j = i+1; j <= last; j++ ){ //Consider for... of loop here?
						let distance = Math.round(Math.sqrt(Math.pow(clickEvents[i].event.clientX - clickEvents[j].event.clientX, 2) + Math.pow(clickEvents[i].event.clientY - clickEvents[j].event.clientY, 2)));
						if ( distance > max_distance ){
							max_distance = distance;
						}

						//Ignore if distance is outside 100px radius
						if ( distance > 100 ){
							return null; //Return null because false will prevent regular clicks!
						}
					}
				}

				//If we have not returned null by now, we have a set of rage clicks
				let thisEvent = {
					event: e,
					category: 'Rage Clicks',
					action: 'Detected', //GA4 Name: "rage_clicks"?
					clicks: numberOfClicks,
					period: timeDiff,
					selector: nebula.domTreeToString(e.target),
				};

				thisEvent.description = numberOfClicks + ' clicks in ' + timeDiff + ' seconds detected within ' + max_distance + 'px of ' + thisEvent.selector;

				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.description, {'nonInteraction': true}); //Non-interaction because if the user exits due to this it should be considered a bounce
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-rage-clicks'}));

				clickEvents.splice(clickEvents.length-5, 5); //Remove unused click points
			}
		});

		//Skip to Content and other screen reader link focuses (which indicate screenreader software is being used in this session)
		nebula.dom.document.on('focus', '.sr-only', function(e){
			let thisEvent = {
				event: e,
				category: 'Accessibility Links',
				action: 'Focus', //GA4 Name: "accessibility_links"?
				linkText: jQuery(this).text().trim()
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText, {'nonInteraction': true}); //Non-interaction because they are not actually taking action and these links do not indicate engagement
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-accessibility-link'}));
		});

		//Skip to Content and other screen reader link clicks (which indicate screenreader software is being used in this session)
		nebula.dom.document.on('click', '.sr-only', function(e){
			let thisEvent = {
				event: e,
				category: 'Accessibility Links',
				action: 'Click', //GA4 Name: "accessibility_links"?
				intent: ( e.which >= 2 )? 'Intent' : 'Explicit',
				linkText: jQuery(this).text().trim()
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.linkText, {'nonInteraction': true}); //Non-interaction because these links do not indicate engagement
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-accessibility-link'}));
		});

		//Video Enter Picture-in-Picture //https://caniuse.com/#feat=picture-in-picture
		nebula.dom.document.on('enterpictureinpicture', 'video', function(e){
			let thisEvent = {
				event: e,
				category: 'Videos',
				action: 'Enter Picture-in-Picture',  //GA4 Name: "video_pip"?
				videoID: e.target.id
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.videoID, {'nonInteraction': true}); //Non-interaction because this may not be triggered by the user.
		});

		//Video Leave Picture-in-Picture
		nebula.dom.document.on('leavepictureinpicture', 'video', function(e){
			let thisEvent = {
				event: e,
				category: 'Videos',
				action: 'Leave Picture-in-Picture', //GA4 Name: "video_pip"?
				videoID: e.target.id
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.videoID, {'nonInteraction': true}); //Non-interaction because this may not be triggered by the user.
		});

		//Word copy tracking
		let copyCount = 0;
		nebula.dom.document.on('cut copy', function(){
			let selection = window.getSelection().toString();
			let words = selection.split(' ');
			let wordsLength = words.length;

			//Track Email or Phone copies as contact intent.
			let emailPhoneAddress = words.join(' ').trim();
			if ( nebula.regex.email.test(emailPhoneAddress) ){
				let thisEvent = {
					category: 'Contact',
					action: 'Email (Copy)', //GA4 Name: "mailto"?
					intent: 'Intent',
					emailAddress: emailPhoneAddress,
					selection: selection,
					words: words,
					wordcount: wordsLength
				};

				ga('set', nebula.analytics.dimensions.contactMethod, 'Mailto');
				ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.emailAddress);
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-copied-email'}));
				nebula.crm('event', 'Email Address Copied');
				nebula.crm('identify', {mailto_contacted: thisEvent.emailAddress});
			} else if ( nebula.regex.address.test(emailPhoneAddress) ){
				let thisEvent = {
					category: 'Contact',
					action: 'Street Address (Copy)',
					intent: 'Intent',
					address: emailPhoneAddress,
					selection: selection,
					words: words,
					wordcount: wordsLength
				};

				ga('set', nebula.analytics.dimensions.contactMethod, 'Street Address');
				ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.address);
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-copied-address'}));
				nebula.crm('event', 'Street Address Copied');
			} else {
				let alphanumPhone = emailPhoneAddress.replaceAll(/\W/g, ''); //Keep only alphanumeric characters
				let firstFourNumbers = parseInt(alphanumPhone.substring(0, 4)); //Store the first four numbers as an integer

				//If the first three/four chars are numbers and the full string is either 10 or 11 characters (to capture numbers with words) -or- if it matches the phone RegEx pattern
				if ( (!isNaN(firstFourNumbers) && firstFourNumbers.toString().length >= 3 && (alphanumPhone.length === 10 || alphanumPhone.length === 11)) || nebula.regex.phone.test(emailPhoneAddress) ){
					let thisEvent = {
						category: 'Contact',
						action: 'Phone (Copy)', //GA4 Name: "click_to_call"?
						intent: 'Intent',
						phoneNumber: emailPhoneAddress,
						selection: selection,
						words: words,
						wordcount: wordsLength
					};

					ga('set', nebula.analytics.dimensions.contactMethod, 'Click-to-Call');
					ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
					nebula.dom.document.trigger('nebula_event', thisEvent);
					ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.phoneNumber);
					window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-copied-phone'}));
					nebula.crm('event', 'Phone Number Copied');
					nebula.crm('identify', {phone_contacted: thisEvent.phoneNumber});
				}
			}

			let thisEvent = {
				category: 'Copied Text',
				action: 'Copy', //This is not used for the below events //GA4 Name: "copy_text"?
				intent: 'Intent',
				phoneNumber: emailPhoneAddress,
				selection: selection,
				words: words,
				wordcount: wordsLength
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);

			if ( copyCount < 5 ){
				if ( words.length > 8 ){
					words = words.slice(0, 8).join(' ');
					ga('send', 'event', thisEvent.category, words.length + ' words', words + '... [' + wordsLength + ' words]'); //GA4: This will need to change significantly. Event Name: "copy_text"?
				} else {
					if ( selection.trim() === '' ){
						ga('send', 'event', thisEvent.category, '[0 words]'); //GA4: This will need to change significantly. Event Name: "copy_text"?
					} else {
						ga('send', 'event', thisEvent.category, words.length + ' words', selection, words.length); //GA4: This will need to change significantly. Event Name: "copy_text"?
					}
				}

				ga('send', 'event', thisEvent.category, words.length + ' words', words + '... [' + wordsLength + ' words]'); //GA4: This will need to change significantly. Event Name: "copy_text"?
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-copied-text'}));
				nebula.crm('event', 'Text Copied');
			}

			copyCount++;
		});

		//AJAX Errors
		nebula.dom.document.ajaxError(function(e, jqXHR, settings, thrownError){
			let errorMessage = thrownError;
			if ( jqXHR.status === 0 ){ //A status of 0 means the error is unknown. Possible network connection issue (like a blocked request).
				errorMessage = 'Unknown error';
			}

			ga('send', 'exception', {'exDescription': '(JS) AJAX Error (' + jqXHR.status + '): ' + errorMessage + ' on ' + settings.url, 'exFatal': true});
			window.dataLayer.push({'event': 'nebula-ajax-error', 'error': errorMessage});
			nebula.crm('event', 'AJAX Error');
		});

		//Window Errors
		window.addEventListener('error', function(error){
			let errorMessage = error.message + ' at ' + error.lineno + ' of ' + error.filename;
			if ( error.message.toLowerCase().includes('script error') ){ //If it is a script error
				errorMessage = 'Script error (An error occurred in a script hosted on a different domain)'; //No additional information is available because of the browser's same-origin policy. Use CORS when possible to get additional information.
			}

			ga('send', 'exception', {'exDescription': '(JS) ' + errorMessage, 'exFatal': false}); //Is there a better way to detect fatal vs non-fatal errors?
			window.dataLayer.push({'event': 'nebula-window-error', 'error': errorMessage});
			nebula.crm('event', 'JavaScript Error');
			nebula.usage(error);
		}, {passive: true});

		//Reporting Observer deprecations and interventions
		//@todo Nebula 0: This may be causing "aw snap" errors in Chrome. Disabling for now until the feature is more stable.
		//https://caniuse.com/#feat=mdn-api_reportingobserver
	/*
		if ( typeof window.ReportingObserver !== 'undefined' ){ //Chrome 68+
			let nebulaReportingObserver = new ReportingObserver(function(reports, observer){
				for ( report of reports ){
					if ( !report.body.sourceFile.includes('extension') ){ //Ignore browser extensions
						ga('send', 'exception', {'exDescription': '(JS) Reporting Observer [' + report.type + ']: ' + report.body.message + ' in ' + report.body.sourceFile + ' on line ' + report.body.lineNumber, 'exFatal': false});
					}
				}
			}, {buffered: true});
			nebulaReportingObserver.observe();
		}
	*/

		//Capture Print Intent
		//Note: This sends 2 events per print (beforeprint and afterprint). If one occurs more than the other we can remove one.
		if ( 'matchMedia' in window ){ //IE10+
			let mediaQueryList = window.matchMedia('print');
			mediaQueryList.addListener(function(mql){
				if ( mql.matches ){
					sendPrintEvent('Before Print', 'mql.matches');
				} else {
					sendPrintEvent('After Print', '!mql.matches');
				}
			});
		} else {
			window.onbeforeprint = sendPrintEvent('Before Print', 'onbeforeprint');
			window.onafterprint = sendPrintEvent('After Print', 'onafterprint');
		}
		function sendPrintEvent(action, trigger){
			let thisEvent = {
				category: 'Print',
				action: action, //GA4 Name: "print"?
				label: 'User triggered print via ' + trigger,
				intent: 'Intent'
			};

			ga('set', nebula.analytics.dimensions.eventIntent, thisEvent.intent);
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-print'}));
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', thisEvent.category);
		}

		//Detect Adblock
		if ( nebula.user.client.bot === false && nebula.site.options.adblock_detect ){ //If not a bot and adblock detection is active
			window.performance.mark('(Nebula) Detect AdBlock [Start]');

			//Attempt to retrieve a fake ad file
			fetch(nebula.site.directory.template.uri + '/assets/js/vendor/autotrack.js', { //This is not the real autotrack library
				importance: 'low',
				cache: 'force-cache'
			}).then(function(response){
				nebula.session.flags.adblock = false;
			}).catch(function(error){
				nebula.dom.html.addClass('ad-blocker');
				ga('set', nebula.analytics.dimensions.blocker, 'Ad Blocker');
				if ( nebula.session.flags.adblock !== true ){ //If this is the first time blocking it, log it
					ga('send', 'event', 'Ad Blocker', 'Blocked', 'This user is using ad blocking software.', {'nonInteraction': true}); //Uses an event because it is asynchronous!
					window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-adblock-detected'}));
					nebula.session.flags.adblock = true;
				}
			}).finally(function(){
				window.performance.mark('(Nebula) Detect AdBlock [End]');
				window.performance.measure('(Nebula) Detect AdBlock', '(Nebula) Detect AdBlock [Start]', '(Nebula) Detect AdBlock [End]');
			});
		}

		//DataTables Filter
		nebula.dom.document.on('keyup', '.dataTables_filter input', function(e){
			let oThis = jQuery(this);
			let thisEvent = {
				event: e,
				category: 'DataTables',
				action: 'Search Filter', //GA4 Name: "search"?
				query: oThis.val().toLowerCase().trim()
			};

			nebula.debounce(function(){
				nebula.dom.document.trigger('nebula_event', thisEvent);
				ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.query);
				window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-datatables'}));
			}, 1000, 'datatables_search_filter');
		});

		//DataTables Sorting
		nebula.dom.document.on('click', 'th.sorting', function(e){
			let thisEvent = {
				event: e,
				category: 'DataTables',
				action: 'Sort', //GA4 Name: "datatables_sort"?
				heading: jQuery(this).text()
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.heading);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-datatables'}));
		});

		//DataTables Pagination
		nebula.dom.document.on('click', 'a.paginate_button ', function(e){
			let thisEvent = {
				event: e,
				category: 'DataTables',
				action: 'Paginate', //GA4 Name: "datatables_paginate"?
				page: jQuery(this).text()
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.page);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-datatables'}));
		});

		//DataTables Show Entries
		nebula.dom.document.on('change', '.dataTables_length select', function(e){
			let thisEvent = {
				event: e,
				category: 'DataTables',
				action: 'Shown Entries Change', //Number of visible rows select dropdown
				selected: jQuery(this).val()
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.selected);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-datatables'}));
		});

		nebula.scrollDepth();
		nebula.ecommerceTracking();
	}, 'nebula event tracking');
};

//Ecommerce event tracking
//Note: These supplement the plugin Enhanced Ecommerce for WooCommerce
nebula.ecommerceTracking = async function(){
	if ( nebula?.site?.ecommerce ){
		//Add to Cart clicks
		nebula.dom.document.on('click', 'a.add_to_cart, .single_add_to_cart_button', function(e){ //@todo "Nebula" 0: is there a trigger from WooCommerce this can listen for?
			let thisEvent = {
				event: e,
				category: 'Ecommerce',
				action: 'Add to Cart', //GA4 Name: "add_to_cart"
				product: jQuery(this).attr('data-product_id')
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.product);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-add-to-cart'}));
			if ( typeof fbq === 'function' ){fbq('track', 'AddToCart');}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', 'Ecommerce Add to Cart');
		});

		//Update cart clicks
		nebula.dom.document.on('click', '.button[name="update_cart"]', function(e){
			let thisEvent = {
				event: e,
				category: 'Ecommerce',
				action: 'Update Cart Button',
				label: 'Update Cart button click'
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-update-cart'}));
			nebula.crm('event', 'Ecommerce Update Cart');
		});

		//Product Remove buttons
		nebula.dom.document.on('click', '.product-remove a.remove', function(e){
			let thisEvent = {
				event: e,
				category: 'Ecommerce',
				action: 'Remove This Item', //GA4 Name: "remove_from_cart"
				product: jQuery(this).attr('data-product_id')
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.product);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-remove-item'}));
			nebula.crm('event', 'Ecommerce Remove From Cart');
		});

		//Proceed to Checkout
		nebula.dom.document.on('click', '.wc-proceed-to-checkout .checkout-button', function(e){
			let thisEvent = {
				event: e,
				category: 'Ecommerce',
				action: 'Proceed to Checkout Button', //GA4 Name: "begin_checkout"
				label: 'Proceed to Checkout button click'
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-proceed-to-checkout'}));
			if ( typeof fbq === 'function' ){fbq('track', 'InitiateCheckout');}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', 'Ecommerce Proceed to Checkout');
		});

		//Checkout form timing
		nebula.dom.document.on('click focus', '#billing_first_name', function(e){
			nebula.timer('(Nebula) Ecommerce Checkout', 'start');

			let thisEvent = {
				event: e,
				category: 'Ecommerce',
				action: 'Started Checkout Form', //GA4 Name: "checkout_progress"?
				label: 'Began filling out the checkout form (Billing First Name)'
			};

			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-started-checkout-form'}));
			nebula.crm('event', 'Ecommerce Started Checkout Form');
		});

		//Place order button
		nebula.dom.document.on('click', '#place_order', function(e){
			let thisEvent = {
				event: e,
				category: 'Ecommerce',
				action: 'Place Order Button', //GA4 Name: "purchase"
				label: 'Place Order button click'
			};

			ga('send', 'timing', 'Ecommerce', 'Checkout Form', Math.round(nebula.timer('(Nebula) Ecommerce Checkout', 'end')), 'Billing details start to Place Order button click');
			nebula.dom.document.trigger('nebula_event', thisEvent);
			ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label);
			window.dataLayer.push(Object.assign(thisEvent, {'event': 'nebula-place-order-button'}));
			if ( typeof fbq === 'function' ){fbq('track', 'Purchase');}
			if ( typeof clarity === 'function' ){clarity('set', thisEvent.category, thisEvent.action);}
			nebula.crm('event', 'Ecommerce Placed Order');
			nebula.crm('identify', {hs_lifecyclestage_customer_date: 1}); //@todo "Nebula" 0: What kind of date format does Hubspot expect here?
		});
	}
};

//Track Nebula framework errors for quality assurance. This will need to be updated for GA4 most likely.
nebula.usage = async function(error){
	if ( error.filename.match(/themes\/Nebula-?(main|parent|\d+\.\d+)?\//i) ){ //If the error is in a Nebula parent file
		let errorMessage = '(JS) ' + error.message + ' at ' + error.lineno + ' of ' + error.filename;
		navigator.sendBeacon && navigator.sendBeacon('https://www.google-analytics.com/collect', [
			'v=1', //Protocol Version
			'tid=UA-36461517-5', //Tracking ID
			'cid=' + nebula.user.cid,
			'ua=' + nebula.user.client.user_agent, //User Agent
			'dl=' + window.location.href, //Page
			'dt=' + document.title, //Title
			't=exception', //Hit Type
			'exd=' + errorMessage, //Exception Detail
			'exf=1', //Fatal Exception?
			'cd1=' + nebula.site.home_url, //Homepage URL
			'cd2=' + Date.now(), //UNIX Time
			'cd6=' + nebula.version.number, //Nebula version
			'cd5=' + nebula.site.directory.root, //Site_URL
			'cd7=' + nebula.user.client.user_agent, //GA CID
			'cd9=' + nebula.site.is_child, //Is child theme?
			'cd12=' + window.location.href, //Permalink
			'cn=Nebula Usage', //Campaign
			'cs=' + nebula.site.home_url, //Source
			'cm=WordPress', //Medium
		].join('&'));
	}
};

//Detect scroll depth
//Note: This is a default GA4 event and is not needed to be tracked in Nebula. Consider deleting entirely.
nebula.scrollDepth = async function(){
	if ( window.performance ){ //Safari 11+
		let scrollReady = performance.now();
		let reachedBottom = false; //Flag for optimization after detection is finished
		let excessiveScrolling = false; //Flag for optimization after detection is finished
		let lastScrollCheckpoint = nebula.dom.window.scrollTop(); //Set a checkpoint of the current scroll distance to subtract against later
		let totalScrollDistance = 0;
		let excessiveScrollThreshold = nebula.dom.document.height()*2; //Set the threshold for an excessive scroll distance

		let scrollDepthHandler = function(){
			//Only check for initial scroll once
			nebula.once(function(){
				nebula.scrollBegin = performance.now()-scrollReady; //Calculate when the first scroll happens
				if ( nebula.scrollBegin > 250 ){ //Try to avoid autoscrolls on pageload
					let thisEvent = {
						category: 'Scroll Depth',
						action: 'Began Scrolling',
						scrollStart: nebula.dom.body.scrollTop() + 'px',
						timeBeforeScrollStart: Math.round(nebula.scrollBegin)
					};
					thisEvent.label = 'Initial scroll started at ' + thisEvent.scrollStart;
					nebula.dom.document.trigger('nebula_event', thisEvent);
					ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.scrollStart, thisEvent.scrollStartTime, {'nonInteraction': true}); //Event value is time until scrolling.
				}
			}, 'begin scrolling');

			//Check scroll distance periodically
			nebula.throttle(function(){
				//Total Scroll Distance
				if ( !excessiveScrolling ){
					totalScrollDistance += Math.abs(nebula.dom.window.scrollTop() - lastScrollCheckpoint); //Increase the total scroll distance (always positive regardless of scroll direction)
					lastScrollCheckpoint = nebula.dom.window.scrollTop(); //Update the checkpoint
					if ( totalScrollDistance >= excessiveScrollThreshold ){
						excessiveScrolling = true; //Set to true to disable excessive scroll tracking after it is detected

						nebula.once(function(){
							let thisEvent = {
								category: 'Scroll Depth',
								action: 'Excessive Scrolling',
								label: 'User scrolled ' + excessiveScrollThreshold + 'px (or more) on this page.',
							};
							nebula.dom.document.trigger('nebula_event', thisEvent);
							ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.label, {'nonInteraction': true});
						}, 'excessive scrolling');
					}
				}

				//When user reaches the bottom of the page
				if ( !reachedBottom ){
					if ( (nebula.dom.window.height()+nebula.dom.window.scrollTop()) >= nebula.dom.document.height() ){ //If user has reached the bottom of the page
						reachedBottom = true;

						nebula.once(function(){
							let thisEvent = {
								category: 'Scroll Depth',
								action: 'Entire Page',
								distance: nebula.dom.document.height(),
								scrollEnd: performance.now()-(nebula.scrollBegin+scrollReady),
							};

							thisEvent.timetoScrollEnd = Math.round(thisEvent.scrollEnd);

							nebula.dom.document.trigger('nebula_event', thisEvent);
							ga('send', 'event', thisEvent.category, thisEvent.action, thisEvent.distance, thisEvent.timetoScrollEnd, {'nonInteraction': true}); //Event value is time to reach end
							window.removeEventListener('scroll', scrollDepthHandler);
						}, 'end scrolling');
					}
				}

				//Stop listening to scroll after no longer necessary
				if ( reachedBottom && excessiveScrolling ){
					window.removeEventListener('scroll', scrollDepthHandler); //Stop watching scrolling– no longer needed if all detections are true
				}
			}, 1000, 'scroll depth');
		};

		window.addEventListener('scroll', scrollDepthHandler); //Watch for scrolling ("scroll" is passive by default)
	}
};

//Send data to the CRM
nebula.crm = async function(action, data, sendNow = true){
	if ( nebula.isDoNotTrack() ){
		return false;
	}

	if ( typeof _hsq === 'undefined' ){
		return false;
	}

	if ( !action || !data || typeof data == 'function' ){
		console.error('Action and Data Object are both required.');
		ga('send', 'exception', {'exDescription': '(JS) Action and Data Object are both required in nebula.crm()', 'exFatal': false});
		return false; //Action and Data are both required.
	}

	if ( action === 'identify' ){
		_hsq.push(["identify", data]);

		jQuery.each(data, function(key, value){
			nebula.user[key] = value;
		});

		if ( sendNow ){
			//Send a virtual pageview because event data doesn't work with free Hubspot accounts (and the identification needs a transport method)
			_hsq.push(['setPath', window.location.href.replace(nebula.site.directory.root, '') + '#virtual-pageview/identify']);
			_hsq.push(['trackPageView']);
		}
		//_hsq.push(["trackEvent", data]); //If using an Enterprise Marketing subscription, use this method instead of the trackPageView above

		//Check if email was identified or just supporting data
		if ( 'email' in data ){
			if ( !nebula.user.known && nebula.regex.email.test(data['email']) ){
				nebula.dom.document.trigger('nebula_crm_identification', {email: nebula.regex.email.test(data['email']), data: data});
				ga('send', 'event', 'CRM', 'Contact Identified', "A contact's email address in the CRM has been identified.");
				nebula.user.known = true;
			}
		} else {
			nebula.dom.document.trigger('nebula_crm_details', {data: data});
			ga('send', 'event', 'CRM', 'Supporting Information', 'Information associated with this user has been identified.');
		}
	}

	if ( action === 'event' ){
		//Hubspot events are only available with an Enterprise Marketing subscription
		//Refer to this documentation for event names and IDs: https://developers.hubspot.com/docs/methods/tracking_code_api/tracking_code_overview#idsandnames
		_hsq.push(["trackEvent", data]);

		_hsq.push(['setPath', window.location.href.replace(nebula.site.directory.root, '') + '#virtual-pageview/' + data]);
		let oldTitle = document.title;
		document.title = document.title + ' (Virtual)';
		_hsq.push(['trackPageView']);
		document.title = oldTitle;
	}

	nebula.dom.document.trigger('crm_data', data);
};

//Easily send form data to nebula.crm() with crm-* classes
//Add a class to the input field with the category to use. Ex: crm-firstname or crm-email or crm-fullname
//Call this function before sending a ga() event because it sets dimensions too
nebula.crmForm = async function(formID){
	let crmFormObj = {};

	if ( formID ){
		crmFormObj['form_contacted'] = 'CF7 (' + formID + ') Submit Attempt'; //This is triggered on submission attempt, so it may capture abandoned forms due to validation errors.
	}

	jQuery('form [class*="crm-"]').each(function(){
		if ( jQuery(this).val().trim().length ){
			if ( jQuery(this).attr('class').includes('crm-notable_poi') ){
				ga('set', nebula.analytics.dimensions.poi, jQuery('.notable-poi').val());
			}

			let cat = /crm-([a-z\_]+)/g.exec(jQuery(this).attr('class'));
			if ( cat ){
				let thisCat = cat[1];
				crmFormObj[thisCat] = jQuery(this).val();
			}
		}
	});

	if ( Object.keys(crmFormObj).length ){
		nebula.crm('identify', crmFormObj);
	}
};