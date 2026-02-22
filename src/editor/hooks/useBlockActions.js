/**
 * useBlockActions Hook
 *
 * Provides a function to execute block actions from AI responses,
 * with status tracking for UI feedback.
 *
 * @package ABW_AI
 */

import { useState, useCallback } from '@wordpress/element';
import { executeBlockActions } from '../utils/block-actions';

/**
 * Hook to execute block actions with status tracking.
 *
 * @return {Object} { executeActions, isExecuting, actionStatus, lastResults }
 */
export function useBlockActions() {
	const [ isExecuting, setIsExecuting ] = useState( false );
	const [ actionStatus, setActionStatus ] = useState( null );
	const [ lastResults, setLastResults ] = useState( null );

	const executeActions = useCallback( async ( actions ) => {
		if ( ! actions || ! Array.isArray( actions ) || actions.length === 0 ) {
			return [];
		}

		setIsExecuting( true );
		setActionStatus( { message: 'Starting...', status: 'executing' } );

		try {
			const results = await executeBlockActions( actions, ( status ) => {
				setActionStatus( status );
			} );

			setLastResults( results );
			setIsExecuting( false );
			setActionStatus( null );

			return results;
		} catch ( error ) {
			setIsExecuting( false );
			setActionStatus( {
				message: `Error: ${ error.message }`,
				status: 'error',
			} );
			setLastResults( [ { action: 'unknown', success: false, error: error.message } ] );

			return [];
		}
	}, [] );

	return {
		executeActions,
		isExecuting,
		actionStatus,
		lastResults,
	};
}
