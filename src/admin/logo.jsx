/**
 * The show.fm icon, inline so the page loads no extra file.
 */
import { Path, SVG } from '@wordpress/primitives';

export default function Logo() {
	return (
		<SVG
			className="showfm-logo"
			viewBox="0 0 512 512"
			width="28"
			height="28"
			aria-hidden="true"
			focusable="false"
		>
			<Path
				fill="#7e22ce"
				d="M6939.367 5985.688c0 48 28.8 81.6 85.2 81.6 51.6 0 81.6-36 81.6-82.8 0-48-31.2-80.4-85.2-80.4-50.4 0-81.6 34.8-81.6 81.6"
				transform="translate(-20809.584 -17698.747)scale(2.99961)"
			/>
			<Path
				fill="#fff"
				stroke="#fff"
				strokeWidth=".35"
				d="M1038.031 1661.287v59.651c0 6.828-4.989 12.37-11.135 12.37-6.145 0-11.134-5.542-11.134-12.37v-59.651c0-6.827 4.989-12.37 11.134-12.37s11.135 5.543 11.135 12.37"
				transform="matrix(2.9626 0 0 2.66666 -2787.373 -4253.629)"
			/>
			<Path
				fill="#fff"
				stroke="#fff"
				strokeWidth=".41"
				d="M1038.031 1666.895v48.436c0 9.922-4.989 17.977-11.135 17.977-6.145 0-11.134-8.055-11.134-17.977v-48.436c0-9.922 4.989-17.978 11.134-17.978s11.135 8.056 11.135 17.978"
				transform="matrix(2.9626 0 0 1.83486 -2673.42 -2846.961)"
			/>
			<Path
				fill="#fff"
				stroke="#fff"
				strokeWidth=".43"
				d="M1038.031 1672.156v37.913c0 12.826-4.989 23.239-11.135 23.239-6.145 0-11.134-10.413-11.134-23.239v-37.913c0-12.826 4.989-23.239 11.134-23.239s11.135 10.413 11.135 23.239"
				transform="matrix(2.9626 0 0 1.41946 -2899.16 -2144.46)"
			/>
		</SVG>
	);
}
