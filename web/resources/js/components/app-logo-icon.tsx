import type { SVGAttributes } from 'react';

/** StockSense AI mark: an outlined stock box (cube). Inherits colour via `fill-current`. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.2 6.4 3.55L12 11.3 5.6 7.75 12 4.2ZM5 9.5l6 3.33v7L5 16.5v-7Zm14 0v7l-6 3.33v-7L19 9.5Z"
            />
        </svg>
    );
}
