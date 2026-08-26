/**
 * Taking a photo with the device's camera, on the offer-to-volunteer form.
 *
 * ── Entirely an enhancement ──────────────────────────────────────────────────
 * The file input is the feature. This adds a second way to fill it, and every
 * path out of here ends with a File on that same input — so the form, the
 * handler and the server-side checks are identical whether the picture came
 * from a camera or from a folder. There is no upload endpoint here and no
 * request of any kind: the photo rides along with the ordinary form post.
 *
 * It removes itself when it cannot work, which is often and is fine:
 *
 *   getUserMedia does not exist on an insecure origin, so a site served over
 *   plain http gets the file input and no button. That is correct rather than
 *   broken, and it is why the button is written by script instead of printed by
 *   PHP — PHP cannot know whether the browser will have the API.
 *
 *   Phones already offer "Take Photo" from a file input with accept="image/*",
 *   natively and better than this could. The button is for desktops.
 *
 *   canvas.toBlob and DataTransfer are both needed to put a File on an input;
 *   without either there is nothing to hand the form.
 *
 * ── Turning the camera off ───────────────────────────────────────────────────
 * Every stream is stopped track by track — on capture, on cancel, and on
 * pagehide. A live camera with its light on, left running because somebody
 * navigated away from a form they were filling in, is the thing that would make
 * a person distrust the whole feature.
 *
 * Hand-written ES5 with no build step; see README.md.
 */
( function () {
	'use strict';

	var text = window.GWC_VT_PHOTO_TEXT || {};

	function say( key, fallback ) {
		return text[ key ] || fallback;
	}

	/* Every capability this needs, asked for before anything is drawn. */
	function supported() {
		return !! (
			navigator.mediaDevices &&
			navigator.mediaDevices.getUserMedia &&
			window.HTMLCanvasElement &&
			HTMLCanvasElement.prototype.toBlob &&
			window.DataTransfer &&
			window.File
		);
	}

	function setUp( field ) {
		var input = field.querySelector( '[data-gwcvt-photo-input]' );
		var host = field.querySelector( '[data-gwcvt-photo-camera]' );

		if ( ! input || ! host ) {
			return;
		}

		var stream = null;
		var video = null;

		host.hidden = false;

		var open = document.createElement( 'button' );

		open.type = 'button';
		open.className = 'gwcvt-photo-field__button';
		open.textContent = say( 'use', 'Use my camera instead' );

		var status = document.createElement( 'p' );

		status.className = 'gwcvt-form__help';
		/* Polite rather than assertive: this narrates something the person just
		 * did on purpose, and interrupting them mid-sentence to say so is worse
		 * than telling them a moment later. */
		status.setAttribute( 'role', 'status' );

		var stage = document.createElement( 'div' );

		stage.className = 'gwcvt-photo-field__stage';

		host.appendChild( open );
		host.appendChild( stage );
		host.appendChild( status );

		function release() {
			if ( ! stream ) {
				return;
			}

			stream.getTracks().forEach( function ( track ) {
				track.stop();
			} );

			stream = null;
		}

		function reset() {
			release();
			stage.innerHTML = '';
			video = null;
		}

		function shoot() {
			if ( ! video || ! video.videoWidth ) {
				return;
			}

			var canvas = document.createElement( 'canvas' );

			/* Captured at the camera's own size and left there. The server
			 * re-encodes every photo down to its own maximum edge anyway, and
			 * doing the arithmetic twice is two places for it to disagree. */
			canvas.width = video.videoWidth;
			canvas.height = video.videoHeight;
			canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );

			canvas.toBlob( function ( blob ) {
				if ( ! blob ) {
					return;
				}

				var file = new File( [ blob ], 'photo.jpg', { type: 'image/jpeg' } );
				var carrier = new DataTransfer();

				carrier.items.add( file );

				/* The whole trick. From here the file input holds a real File
				 * and the form knows nothing about where it came from. */
				input.files = carrier.files;

				reset();

				var shown = document.createElement( 'img' );

				shown.className = 'gwcvt-photo-field__shot';
				shown.alt = say( 'taken', 'The photo you just took' );
				shown.src = canvas.toDataURL( 'image/jpeg' );

				stage.appendChild( shown );

				open.textContent = say( 'retake', 'Take another' );
				status.textContent = say( 'ready', 'Photo taken. Send the form to submit it, or take another.' );
			}, 'image/jpeg', 0.85 );
		}

		function start() {
			navigator.mediaDevices.getUserMedia( { video: { facingMode: 'user' }, audio: false } ).then( function ( granted ) {
				stream = granted;
				stage.innerHTML = '';

				video = document.createElement( 'video' );
				video.className = 'gwcvt-photo-field__preview';
				video.setAttribute( 'playsinline', '' );
				video.setAttribute( 'aria-label', say( 'preview', 'Camera preview' ) );
				video.muted = true;
				video.srcObject = stream;
				video.play();

				var take = document.createElement( 'button' );

				take.type = 'button';
				take.className = 'gwcvt-photo-field__button';
				take.textContent = say( 'take', 'Take the photo' );
				take.addEventListener( 'click', shoot );

				var stop = document.createElement( 'button' );

				stop.type = 'button';
				stop.className = 'gwcvt-photo-field__button';
				stop.textContent = say( 'stop', 'Turn the camera off' );
				stop.addEventListener( 'click', function () {
					reset();
					status.textContent = '';
					open.textContent = say( 'use', 'Use my camera instead' );
				} );

				stage.appendChild( video );
				stage.appendChild( take );
				stage.appendChild( stop );

				status.textContent = '';
			} ).catch( function () {
				/* Refused, or there is no camera, or something else has it. One
				 * message for all of them: the person's next move is the same
				 * either way, and the file input is still sitting above. */
				status.textContent = say( 'denied', 'Your browser did not let us use the camera. You can still choose a photo from your device above.' );
			} );
		}

		open.addEventListener( 'click', function () {
			if ( stream ) {
				reset();
			}

			start();
		} );

		/* pagehide rather than unload: unload does not fire reliably on mobile
		 * Safari, which is where a camera left running is most noticeable. */
		window.addEventListener( 'pagehide', release );
	}

	function init() {
		if ( ! supported() ) {
			return;
		}

		var fields = document.querySelectorAll( '[data-gwcvt-photo]' );
		var i;

		for ( i = 0; i < fields.length; i++ ) {
			setUp( fields[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
